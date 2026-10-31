<?php
// Copy to config.php. Keep credentials private. Use a NEW random setup key (32+ characters).
return [
    'dsn' => 'mysql:host=localhost;dbname=YOUR_FULL_DATABASE_NAME;charset=utf8mb4',
    'username' => 'YOUR_DATABASE_USER',
    'password' => 'YOUR_DATABASE_PASSWORD',
    'setup_key' => 'REPLACE_WITH_A_RANDOM_SECRET_OF_AT_LEAST_32_CHARACTERS',
    'app_url' => 'https://billing.example.com',
    'mail_from' => 'office@example.com',
    // Branding shown in the portal, customer emails and download pages. Replace images/logo.png, logo-light.png and logo-icon.png with the client's logos too.
    'brand' => 'Billing Portal',
    'short_name' => 'Billing',
    'location' => 'City, Country',
    'timezone' => 'Africa/Dar_es_Salaam',
    // Company details copied onto each new quotation and invoice.
    'company' => 'Example Company Limited',
    'address' => "Building, Street,\nCity · P.O. Box 0000",
    'email' => 'office@example.com',
    'phone' => '+000 000 000 000',
    'currency' => 'TZS',
    'payment_details' => 'Enter your bank name, account name and account number here.',
    // Optional: folder for supporting PDFs attached to documents. Leave out to use private-files inside the portal folder.
    // 'attachments_dir' => '/home/USER/erp-attachments',
];
