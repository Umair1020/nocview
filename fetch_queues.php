<?php
   // Suppress PHP notices/warnings during debugging
   error_reporting(E_ERROR);
   header('Content-Type: application/json');

   require('routeros_api.class.php');
   require('config.php');

   $API = new RouterosAPI();
   $queues = [];

if ($API->connect('115.42.67.210', 'api', 'compaq123_')) {
       $API->write('/queue/simple/print');
       $queues = $API->read();
       $API->disconnect();
       echo json_encode($queues);
   } else {
       echo json_encode(['success' => false, 'message' => 'Could not connect']);
   }
   ?>