# CompareDB

A php script that compare 2 mysql db


## Usage
Replace with your database info, database1 is the reference 

```php
// reference
$db1_host = 'localhost';
$db1_user = 'user_database1';
$db1_pass = 'strongPassword123';
$db1_name = 'database1';

// destination
$db2_host = 'localhost';
$db2_user = 'user_database2';
$db2_pass = 'strongPassword123';
$db2_name = 'database2';
```


## Tech Stack

**Client:** [matcha.css](https://matcha.mizu.sh)

**Server:** php with mysqli module, MySQL or MariaDB


## License

[![MIT License](https://img.shields.io/badge/License-MIT-green.svg)](https://choosealicense.com/licenses/mit/)
