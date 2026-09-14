# PO Management System

A complete Purchase Order Management System built with PHP, MySQL, HTML, CSS, and JavaScript.

## Features

- ✅ User Authentication
- ✅ Dashboard with Statistics
- ✅ Complete CRUD for Purchase Orders
- ✅ Supplier Management
- ✅ Item/Product Management
- ✅ PO Item Allocation to Stores
- ✅ PO Status Tracking with Logs
- ✅ Item Receiving Functionality
- ✅ Reports Module
- ✅ Responsive UI with Bootstrap 5
- ✅ DataTables Integration
- ✅ Charts Visualization

## Requirements

- PHP 7.4+
- MySQL 5.7+
- Apache or Nginx
- Web Browser

## Installation

1. Clone or download the project to your web server directory
2. Create a database named `po_system`
3. Import the `database.sql` file
4. Update database credentials in `config/database.php`
5. Access the application via browser
6. Login with demo credentials:
   - Email: admin@posystem.lk
   - Password: password

## Project Structure

po-management-system/
├── config/ # Configuration files
├── includes/ # PHP includes (header, footer, functions)
├── assets/ # CSS, JS, images
├── pages/ # All page templates
│ ├── pos/ # Purchase Order pages
│ ├── suppliers/ # Supplier management
│ ├── items/ # Item management
│ └── reports/ # Reports
├── api/ # API endpoints for AJAX
├── index.php # Main entry point
├── login.php # Login page
├── logout.php # Logout script
└── .htaccess # URL rewriting


## Default Login Credentials

- **Email:** admin@posystem.lk
- **Password:** password

## API Endpoints

- `api/pos.php?action=store` - Create PO
- `api/pos.php?action=update` - Update PO
- `api/pos.php?action=delete&id=X` - Delete PO
- `api/pos.php?action=update-status` - Update PO status
- `api/pos.php?action=receive` - Receive items

## Security

- Password hashing for user authentication
- Session-based login system
- SQL injection prevention with prepared statements
- XSS protection with output sanitization
- CSRF protection tokens

## Browser Support

- Chrome (latest)
- Firefox (latest)
- Safari (latest)
- Edge (latest)
- Opera (latest)

## License

MIT License

MIT License
14. Database SQL File
Create database.sql with the SQL script you provided earlier.

Complete Installation Steps:
Create the project folder in C:\xampp\htdocs\po-management-system

Copy all the files to their respective locations

Import the database using phpMyAdmin or MySQL command line

Open browser and go to http://localhost/po-management-system/install.php

Follow the installation wizard

Login with demo credentials

Start using the PO Management System!