# b2_multi
Backblaze B2 PHP library with parallel operations via curl_multi

# Reqs

 * Backblaze B2 account
 * Application key with access to listBuckets operation
 * CloudFlare domain CNAMEd to Backblaze download endpoint as described here: https://help.backblaze.com/hc/en-us/articles/217666928-Using-Backblaze-B2-with-the-Cloudflare-CDN
 * Redis instance

# Description

This class uses redis to cache access information for performance and cost saving reasons. With this class you can upload and download files simultaniusly. Downloading is performed via Clouflare & Backblaze parnership to cut download costs to zero.


# Examples:

```php
$b2= new B2('SecretId','SecretKey','https://my.download.domain',$RedisInstance);

$b2->putObjects('myBucket', ['file1.txt','file2.txt','file4.txt'], 
[
 'Content 1'
,'Content 2'
,'Content 3'
]
);

$b2->getObjects('MyBucket', ['file2.txt','file3.txt']);

$b2->deleteObjects('MyBucket', ['file1.txt','file3.txt']);
```
