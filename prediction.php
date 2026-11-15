<?php
// Halaman prediksi terpisah telah dipensiunkan; hasil kini tersedia pada detail Data Santri.
require __DIR__ . '/config.php';
require_role('admin');
redirect('students.php');
