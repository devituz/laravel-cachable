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

    protected function getCacheKey($id = null, $lang = 'uz'): string
    {
        $lang = $this->validateLang($lang);
        $model = strtolower(class_basename($this));
        $langSuffix = "_{$lang}";
        return $id ? "{$model}_{$id}{$langSuffix}" : "{$model}_all{$langSuffix}";
    }

    /**
     * Retrieve all records with source info (redis/db)
     */
    public static function allCached($lang = 'uz')
    {
        $instance = new static;
        $lang = $instance->validateLang($lang);
        $key = $instance->getCacheKey(null, $lang);

        $source = Cache::store('redis')->has($key) ? 'redis' : 'db';

        $data = Cache::store('redis')->rememberForever($key, function () use ($instance, $lang) {
            return $instance->latest()->get()->map(fn($item) => $item->toArray($lang))->all();
        });

        return [
            'source' => $source,
            'data'   => $data,
        ];
    }

    /**
     * Cache a single record
     */
    public function cacheSingle($lang = 'uz'): void
    {
        $lang = $this->validateLang($lang);
        Cache::store('redis')->put(
            $this->getCacheKey($this->id, $lang),
            $this->toArray($lang),
            3600
        );
    }

    /**
     * Retrieve a single record with source info (redis/db)
     */
    public static function getCached($id, $lang = 'uz')
    {
        $instance = new static;
        $lang = $instance->validateLang($lang);
        $key = $instance->getCacheKey($id, $lang);

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

    public function forgetCache(): void
    {
        foreach ($this->supportedLangs as $lang) {
            Cache::store('redis')->forget($this->getCacheKey($this->id, $lang));
            Cache::store('redis')->forget($this->getCacheKey(null, $lang));
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
