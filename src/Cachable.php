<?php

namespace Devituz\LaravelCachable;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

trait Cachable
{

    protected array $supportedLangs = ['uz', 'ru', 'en'];
    protected int $cacheTtl = 3600;
    protected string $cachePrefix = '';

    public function getSupportedLangs(): array
    {
        return property_exists($this, 'supportedLangs') ? $this->supportedLangs : ['uz', 'ru', 'en'];
    }

    public function getCacheTtl(): int
    {
        return property_exists($this, 'cacheTtl') ? $this->cacheTtl : 3600;
    }

    public function getCachePrefix(): string
    {
        return property_exists($this, 'cachePrefix') ? $this->cachePrefix : strtolower(class_basename($this));
    }



    protected function validateLang(?string $lang): string
    {
        $lang = strtolower($lang ?? 'uz');
        return in_array($lang, $this->getSupportedLangs()) ? $lang : 'uz';
    }

    protected function getCacheKey($id = null, $lang = 'uz', $params = []): string
    {
        $lang = $this->validateLang($lang);
        $prefix = $this->getCachePrefix();
        $langSuffix = "_{$lang}";

        if ($id) {
            return "{$prefix}_{$id}{$langSuffix}";
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

            return "{$prefix}_all_{$paramString}{$langSuffix}";
        }

        return "{$prefix}_all{$langSuffix}";
    }

    public static function allCached($lang = 'uz', $params = [])
    {
        $instance = new static;
        $lang = $instance->validateLang($lang);
        $key = $instance->getCacheKey(null, $lang, $params);

        Log::info("allCached key: {$key}");

        $source = Cache::store('redis')->has($key) ? 'redis' : 'db';

        $data = Cache::store('redis')->remember($key, $instance->getCacheTtl(), function () use ($instance, $lang, $params) {
            $query = $instance->latest();

            foreach ($params as $column => $value) {
                if (is_array($value)) {
                    $query->whereIn($column, $value);
                } else {
                    $query->where($column, $value);
                }
            }

            return $query->get()->map(fn($item) => method_exists($item, 'toArray') ? $item->toArray($lang) : $item->toArray())->all();
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

        Log::info("Caching single key: {$key}");

        Cache::store('redis')->put(
            $key,
            method_exists($this, 'toArray') ? $this->toArray($lang) : $this->toArray(),
            $this->getCacheTtl()
        );
    }

    public static function getCached($id, $lang = 'uz', $params = [])
    {
        $instance = new static;
        $lang = $instance->validateLang($lang);
        $key = $instance->getCacheKey($id, $lang, $params);

        Log::info("getCached key: {$key}");

        $source = Cache::store('redis')->has($key) ? 'redis' : 'db';

        $data = Cache::store('redis')->remember($key, $instance->getCacheTtl(), function () use ($id, $instance, $lang) {
            $item = $instance->find($id);
            return $item ? (method_exists($item, 'toArray') ? $item->toArray($lang) : $item->toArray()) : null;
        });

        return [
            'source' => $source,
            'data'   => $data,
        ];
    }

    public function forgetCache($params = []): void
    {
        foreach ($this->getSupportedLangs() as $lang) {
            $singleKey = $this->getCacheKey($this->id, $lang, $params);
            $allKey = $this->getCacheKey(null, $lang, $params);
            Log::info("Forgetting cache keys: {$singleKey}, {$allKey}");
            Cache::store('redis')->forget($singleKey);
            Cache::store('redis')->forget($allKey);
        }
    }

    protected static function bootCachable(): void
    {
        static::created(function ($model) {
            foreach ($model->getSupportedLangs() as $lang) {
                $model->cacheSingle($lang);
            }
        });

        static::updated(function ($model) {
            foreach ($model->getSupportedLangs() as $lang) {
                $model->cacheSingle($lang);
            }
        });

        static::deleted(function ($model) {
            $model->forgetCache();
        });
    }
}
