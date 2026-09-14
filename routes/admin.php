<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Staff console routes
|--------------------------------------------------------------------------
|
| Mounted at /admin with the "admin" middleware group (web + auth + verified
| + staff role check) and the "admin." route-name prefix.
|
| Feature routes belong in the owning module's routes/admin.php, and the
| console's own cross-cutting screens — dashboard, settings, reference data,
| content, staff, audit — belong to the Admin module, which is where they now
| live. Nothing is left for this file, which is the intended end state: the
| console is assembled from its modules rather than declared centrally.
|
*/
