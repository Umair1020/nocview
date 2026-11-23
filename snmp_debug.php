<?php
$ip = '103.246.109.194';
$c  = 'ZiaNoc@2026RO';
echo "v2c: "; var_dump(snmp2_get($ip, $c, '.1.3.6.1.2.1.1.1.0', 3000000, 1));
echo "v1 : "; var_dump(snmpget($ip, $c, '.1.3.6.1.2.1.1.1.0', 3000000, 1));