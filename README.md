# PMS - Port Management System

A PHP-based web application for managing port and terminal operations. This project is designed to support multi-role port administration, shipping operations, container tracking, and financial management in a single system.


## About the Project

PMS is a port management system built to handle the day-to-day operations of a shipping/terminal environment. It helps manage vessel arrivals, cargo handling, berth allocation, inspections, storage, billing, and key operational reporting.

## Key Features

- Role-based dashboard access
  - Admin
  - Shipping company
  - Harbor master
  - Operations
  - Finance

- Vessel and arrival management
  - Record incoming vessel requests
  - Track ETA and booking status

- Cargo and container tracking
  - Monitor containers in transit, stored, or delivered
  - Manage cargo movement within the terminal

- Berth and docking operations
  - Track berth availability and occupancy
  - Manage docking activity

- Customs and inspection workflow
  - Handle inspections and clearance status

- Finance and billing
  - Manage invoices
  - Track payments and overdue balances

- Workforce and task management
  - Monitor active workers, shifts, and assigned tasks

- Dashboard reporting
  - Key KPIs such as revenue, pending arrivals, occupied berths, overdue invoices, and more

- Audit logging
  - Keep records of system activity

## Tech Stack

- PHP
- MySQL
- PDO for database connection
- HTML / CSS / JavaScript
- XAMPP for local development

## Project Structure

- `config/` - database and app configuration
- `includes/` - shared logic and helper files
- `modules/` - main application modules
- `assets/` - CSS and JavaScript files
- `sql/` - database schema and seed scripts
- `login.php`, `dashboard.php`, `signup.php` - main entry points

## How to Run on Your Computer (XAMPP)

1. Install XAMPP and start:
   - Apache
   - MySQL (Use port **3307** instead of **3306**)

2. Clone or download this project into:
   ```bash
   C:\xampp\htdocs\pms
