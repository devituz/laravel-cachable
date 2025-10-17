# 🚀 Laravel Cachable

**LaravelCachable** is a simplified, multi-language **model caching** trait that works with **Redis**.
It automatically caches and purges model data on `created`, `updated`, and `deleted` events.

---

## 📦 Installation

```bash
composer require devituz/laravel-cachable
-----
```


#### Add model
```php
use HasFactory,Cachable;
```


#### Example code
```php
$product = Product::find(1);

// 🔹 Save single record to cache
$product->cacheSingle();

// 🔹 Retrieve single record from cache
$cached = Product::getCached(1);

// 🔹 Retrieve all records from cache
$all = Product::allCached();

// 🔹 Clear cache for a record
$product->forgetCache();



```