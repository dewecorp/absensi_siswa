# Installation Instructions for Excel Import Feature

## Prerequisites

- PHP 7.4 or higher
- Composer installed on your system (https://getcomposer.org/)

## Steps to Install Required Dependencies

1. Open Command Prompt or Terminal in the project directory
2. Run the following command to install the required libraries:

```bash
composer install
```

Or run the batch file for Windows users:
```bash
install_dependencies.bat
```

## What Will Be Installed

- `phpoffice/phpspreadsheet`: A library to read and write Excel files (XLS, XLSX) and other spreadsheet formats

## After Installation

Once the installation is complete:
1. Run the template generator:
   ```bash
   php generate_templates.php
   ```
   Or use the batch file on Windows:
   ```bash
   update_templates.bat
   ```

The import functionality will support:
- XLS files (Excel 97-2003 format)
- XLSX files (Excel 2007+ format)
- CSV files

The system will automatically detect the file type and use the appropriate method to process the file.

## Available Templates

After running the template generator, you'll have Excel templates available for download in the import modals:
- `guru_template.xlsx` - Template for importing teacher data
- `siswa_template.xlsx` - Template for importing student data