<?php

namespace Devituz\LaravelCachable;

use Illuminate\Support\Facades\Cache;

trait Cachable
{
    protected array $supportedLangs = ['uz', 'ru', 'en'];

    protected function validateLang(?string $lang): string
    {
        $lang = strtolower($lang ?? 'uz');
        return in_array($lang, $this->supportedLangs) ? $lang : 'uz';
    }

    protected function getCacheKey($id = null, $lang = 'uz', $params = []): string
    {
        $lang = $this->validateLang($lang);
        $model = strtolower(class_basename($this));
        $langSuffix = "_{$lang}";

        if ($id) {
            return "{$model}_{$id}{$langSuffix}";
        }

        if (!empty($params)) {
            $paramPairs = [];
            foreach ($params as $k => $v) {
                if (is_array($v)) {
                    $v = implode('-', array_map('strval', $v));
                }
                $paramPairs[] = "{$k}_{$v}";
            }

            $paramString = implode('_', $paramPairs);

            if (strlen($paramString) > 100) {
                $paramString = md5($paramString);
            }

            return "{$model}_all_{$paramString}{$langSuffix}";
        }

        return "{$model}_all{$langSuffix}";
    }

    public static function allCached($lang = 'uz', $params = [])
    {
        $instance = new static;
        $lang = $instance->validateLang($lang);

        $key = $instance->getCacheKey(null, $lang, $params);

        $source = Cache::store('redis')->has($key) ? 'redis' : 'db';

        $data = Cache::store('redis')->rememberForever($key, function () use ($instance, $lang, $params) {
            $query = $instance->latest();

            foreach ($params as $column => $value) {
                if (is_array($value)) {
                    $query->whereIn($column, $value);
                } else {
                    $query->where($column, $value);
                }
            }

            return $query->get()->map(fn($item) => $item->toArray($lang))->all();
        });

        return [
            'source' => $source,
            'data'   => $data,
        ];
    }

    public function cacheSingle($lang = 'uz', $params = []): void
    {
        $lang = $this->validateLang($lang);
        $key = $this->getCacheKey($this->id, $lang, $params);

        Cache::store('redis')->put(
            $key,
            $this->toArray($lang),
            3600
        );
    }

    public static function getCached($id, $lang = 'uz', $params = [])
    {
        $instance = new static;
        $lang = $instance->validateLang($lang);
        $key = $instance->getCacheKey($id, $lang, $params);

        $source = Cache::store('redis')->has($key) ? 'redis' : 'db';

        $data = Cache::store('redis')->remember($key, 3600, function () use ($id, $instance, $lang) {
            $item = $instance->find($id);
            return $item ? $item->toArray($lang) : null;
        });

        return [
            'source' => $source,
            'data'   => $data,
        ];
    }

    public function forgetCache($params = []): void
    {
        foreach ($this->supportedLangs as $lang) {
            Cache::store('redis')->forget($this->getCacheKey($this->id, $lang, $params));
            Cache::store('redis')->forget($this->getCacheKey(null, $lang, $params));
        }
    }

    protected static function bootCachable(): void
    {
        static::created(function ($model) {
            foreach ($model->supportedLangs as $lang) {
                $model->cacheSingle($lang);
            }
            $model->forgetCache();
        });

        static::updated(function ($model) {
            foreach ($model->supportedLangs as $lang) {
                $model->cacheSingle($lang);
            }
            $model->forgetCache();
        });

        static::deleted(function ($model) {
            $model->forgetCache();
        });
    }
}
