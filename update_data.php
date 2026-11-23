<?php

$liveData = getMikrotikData();
file_put_contents(__DIR__ . '/live_data_full_cache.json', json_encode($liveData));
file_put_contents(__DIR__ . '/live_data_fast_cache.json', json_encode($liveData));