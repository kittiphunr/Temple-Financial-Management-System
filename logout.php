<?php
require __DIR__ . '/includes/bootstrap.php';
if (user()) {
    audit('logout', 'user', user()['id']);
}
$_SESSION = [];
session_destroy();
redirect('index.php');
