# Enterprise PO Management & Matrix Editor Engine

A complete Purchase Order Management and Matrix Editor System built with PHP, MySQL, Tailwind CSS, Bootstrap 5, and JavaScript. Engineered for high-throughput inventory operations with **2M+ records capacity support**, real-time local draft recovery, automated catalog synchronization, and keyboard-first matrix navigation.

---

## 🔑 Key Features

- **Pending PO Filtering Engine**: Restricts active matrix edit views strictly to `Pending` status Purchase Orders to ensure operational data integrity.
- **Real-Time Local Draft Cache**: Automatically caches active edits to browser `localStorage` to safeguard against accidental refreshes, network drops, or session timeouts. Includes a one-click **Restore Draft Cache** option.
- **Automated Cache Purging**: Automatically purges client-side draft cache once changes are safely committed to the database.
- **Atomic Dual-Table Deletion**: Deleting line items removes records concurrently from both `po_items` and master `items` tables safely inside an isolated database transaction.
- **2M+ Large Dataset Optimization**: Built with streaming SQL queries (`fetch_assoc`) to manage high-volume inventory rows and high-throughput input without memory bottlenecks.
- **Manager Authorization Security**: Enforces single-session manager password validation for critical save and delete operations.
- **Complete PO Lifecycle Management**: User authentication, dashboard statistics, supplier & item management, PO receiving functionality, status tracking logs, and analytical reports.

---

## ⌨️ Keyboard Shortcuts

| Shortcut Key | Action |
| :--- | :--- |
| <kbd>Alt</kbd> + <kbd>A</kbd> | Append new matrix row |
| <kbd>Alt</kbd> + <kbd>S</kbd> | Focus Supplier search field |
| <kbd>Alt</kbd> + <kbd>I</kbd> | Focus Item Code input |
| <kbd>Alt</kbd> + <kbd>D</kbd> | Focus Item Description input |
| <kbd>Alt</kbd> + <kbd>1</kbd> | Select Department |
| <kbd>Alt</kbd> + <kbd>2</kbd> | Select Sub-Department |
| <kbd>Alt</kbd> + <kbd>3</kbd> | Select Category |
| <kbd>Alt</kbd> + <kbd>4</kbd> | Select Color |
| <kbd>Alt</kbd> + <kbd>5</kbd> | Select Size |
| <kbd>Enter</kbd> | Select dropdown suggestion or advance field focus |

---

## 📁 Project Structure

```text
po-management-system/
├── config/             # Database connection & system setup
├── includes/           # PHP components (header, sidebar, footer, helper functions)
├── assets/             # CSS, JavaScript engine scripts, images
├── pages/              # Main page modules
│   ├── pos/            # Purchase Order creation & matrix editing engines
│   ├── suppliers/      # Supplier management
│   ├── items/          # Master item catalog
│   └── reports/        # Analytics & reporting modules
├── api/                # REST/AJAX endpoints for backend operations
├── database.sql        # Database schema import script
├── index.php           # Dashboard entry point
├── edit_po.php         # Pending PO Matrix Editor Engine
├── login.php           # User authentication login
└── logout.php          # Session termination

🚀 Installation & Setup
Copy or clone the project folder to C:\xampp\htdocs\PO_SYSTEM (or your web server root).

Create a MySQL database named po_system.

Import the database.sql script into your MySQL database using phpMyAdmin or CLI.

Update your database configuration in config/database.php.

Access the application in your web browser: http://localhost/PO_SYSTEM

Default Demo Credentials
Email: admin@posystem.lk

Password: password

🛡️ Security Features
Prepared statements across all MySQL queries for SQL injection prevention.

Single-session manager password authorization for operational actions.

Password hashing for user authentication and session management.

XSS protection via input sanitization and output escaping.

📄 License & Copyright Notice
Copyright © 2026 ASB Fashion Group of Companies. All Rights Reserved.

This software and associated documentation files are the proprietary property of ASB Fashion Group of Companies. Unauthorized copying, modification, distribution, or reproduction of any part of this system, via any medium, is strictly prohibited without explicit written permission.

Designed and Developed by Vexel IT and Kavindu Bogahawatte (kavizz).
