<?php

//print_r(json_decode('{"id":5251,"username":"primary1","name":"IRIS High","_revision":1342773809,"331_":"2012-07-13","331":"2012-07-13","334":["11"],"334_":"Mr Jones","335":["48"],"335_":"Michael Bennett","336":["244"],"336_":"Afternoon break","338":["253"],"338_":"Breaktime detention","344_":"\\\\","344":"\\\\","410":["2"],"410_":"Refusing to work \/ follow instruction"}', TRUE));

print_r(json_decode('{"a":"\\","b":"\\"}', TRUE));

exit();

$json0 = '{"id":"730","username":"primary1","name":"IRIS High","331_":"2011-08-23","331":"2011-08-23","334":["17"],"334_":"Mrs Khan","335":["54"],"335_":"Rhys Cunliffe","336":["243"],"336_":"Afternoon session 1","337":["248"],"337_":"Classroom","338":["254"],"338_":"Lunchtime detention","344_":"continuous chatting and distracting others","344":"continuous chatting and distracting others","410":["7"],"410_":"Serious disruption of lesson"}';
$json1= '{"id":730,"username":"andrew","name":"IRIS High","_revision":1342257152,"331_":"2011-08-23","331":"2011-08-23","334":["17"],"334_":"Mrs Khan","335":["54"],"335_":"Rhys Cunliffe","336":["243"],"336_":"Afternoon session 1","337":["248"],"337_":"Classroom","338":["254"],"338_":"Lunchtime detention","344_":"continuous chatting and distracting others","344":"continuous chatting and distracting others","408_":"test\\test431t5134tjn\\\\\\7\\78gzo8\\g78\\g\\87og\\78og\\87g\\t6\\T^&*|*&t87T\\78T|7)T|&*T|78T|80T*|&TT|0T|*7t*|t7*T\\7*T*|7t*&T0T|*|6|75|6&%&*%|^&%|&%|%*|&^|&*|&*||||\\\\\\78\\6\\06\\\\&\\%$\\\u00d2f","408":"test\\test431t5134tjn\\\\\\7\\78gzo8\\g78\\g\\87og\\78og\\87g\\t6\\T^&*|*&t87T\\78T|7)T|&*T|78T|80T*|&TT|0T|*7t*|t7*T\\7*T*|7t*&T0T|*|6|75|6&%&*%|^&%|&%|%*|&^|&*|&*||||\\\\\\78\\6\\06\\\\&\\%$\\\u00d2f","410":["7"],"410_":"Serious disruption of lesson"}';

print_r(json_decode($json1, TRUE));

print_r(
array_diff_assoc(json_decode($json0, TRUE), json_decode($json1, TRUE))
);
