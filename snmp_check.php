<?php
$r = @snmp2_real_walk('103.246.109.194', 'ZiaNoc@2026RO', '.1.3.6.1.2.1.31.1.1.1.1', 2000000, 1);
echo $r ? '<pre>'.print_r($r, true) : 'SNMP reply nahi aaya';