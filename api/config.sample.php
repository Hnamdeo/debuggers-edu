<?php
// Copy to the hosting home folder (above public_html), named after the site:
//   debuggers-edu-config.edu.debuggersspace.com.php
// Fill in from hPanel and never commit the real file.
return [
    'db_host' => 'localhost',
    'db_name' => 'u123456789_school',
    'db_user' => 'u123456789_school',
    'db_pass' => 'put-the-password-here',
    // Long random text (at least 24 characters). The same value goes in the GitHub secret
    // SCHOOL_DEPLOY_KEY so each deploy can run new database migrations.
    'deploy_key' => 'put-a-long-random-key-here',
    // Used to build invite links shared with teachers and parents
    'app_url' => 'https://edu.debuggersspace.com',
    // Optional: a Google web client id, for "Continue with Google"
    'google_client_id' => '',
];
