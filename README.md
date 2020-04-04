# b2_multi
Backblaze B2 PHP library with parallel operations via curl_multi

Examples:

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
