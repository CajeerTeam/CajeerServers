<?php
return ['driver' => 'database', 'encryption' => 'libsodium', 'key' => env('VAULT_KEY', '')];
