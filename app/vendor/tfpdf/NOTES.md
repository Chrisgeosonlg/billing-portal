# Bundled PDF dependency

tFPDF 1.33 (LGPL), obtained from https://www.fpdf.org/en/script/script92.php on 2026-09-17. Source package: https://www.fpdf.org/en/script/dl.php?f=zip&id=92 . PHP mbstring and zlib are required. Only the DejaVu Sans regular and bold fonts are bundled; their license is in font/unifont/DejaVu_LICENSE.txt.

Local modification: the two font metric cache write guards in tfpdf.php are disabled. Metrics are calculated in memory, avoiding writable application directories, concurrent partial cache writes and deployment-specific absolute paths. No other library changes. Source is shipped with the application and can be replaced or modified under its LGPL license. Notices and license text are included.
