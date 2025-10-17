<?php

namespace Devituz\LaravelCachable;

use Illuminate\Support\Facades\Cache;

trait Cachable
{
    /**
     * 🔹 Supported languages
     */
    protected array $supportedLangs = ['uz', 'ru', 'en'];

    /**
     * 🔹 Validate and normalize language
     * Falls back to 'uz' if the given language is invalid or missing.
     */
    protected function validateLang(?string $lang): string
    {
        $lang = strtolower($lang ?? 'uz');
        return in_array($lang, $this->supportedLangs) ? $lang : 'uz';
    }

    /**
     * 🔹 Generate dynamic cache key (language-aware)
     */
    protected function getCacheKey($id = null, $lang = 'uz'): string
    {
        $lang = $this->validateLang($lang);
        $model = strtolower(class_basename($this));
        $langSuffix = "_{$lang}";
        return $id ? "{$model}_{$id}{$langSuffix}" : "{$model}_all{$langSuffix}";
    }

    /**
     * 🔹 Retrieve all records with caching (language-aware)
     */
    public static function allCached($lang = 'uz')
    {
        $instance = new static;
        $lang = $instance->validateLang($lang);
        $key = $instance->getCacheKey(null, $lang);

        return Cache::store('redis')->rememberForever($key, function () use ($instance, $lang) {
            return $instance->latest()->get()->map(fn($item) => $item->toArray($lang))->all();
        });
    }

    /**
     * 🔹 Cache a single record (language-aware)
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
     * 🔹 Retrieve a single record from cache (language-aware)
     */
    public static function getCached($id, $lang = 'uz')
    {
        $instance = new static;
        $lang = $instance->validateLang($lang);
        $key = $instance->getCacheKey($id, $lang);

        return Cache::store('redis')->remember($key, 3600, function () use ($id, $instance, $lang) {
            $item = $instance->find($id);
            return $item ? $item->toArray($lang) : null;
        });
    }

    /**
     * 🔹 Clear cache for all supported languages
     */
    public function forgetCache(): void
    {
        foreach ($this->supportedLangs as $lang) {
            Cache::store('redis')->forget($this->getCacheKey($this->id, $lang));
            Cache::store('redis')->forget($this->getCacheKey(null, $lang));
        }
    }

    /**
     * 🔹 Automatically manage cache via model events
     */
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
