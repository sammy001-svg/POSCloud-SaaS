# POSCloud — Multi-Tenant SaaS Point of Sales System

POSCloud is a high-fidelity, enterprise-grade Multi-Tenant SaaS platform designed for retail businesses, pharmacies, supermarkets, and wholesale shops. It features a robust multi-persona architecture supporting **Super Admins**, **Partner Resellers**, and **Business Owners**.

## 🚀 Key Features

### 🏢 For Business Owners (Tenants)
- **High-Fidelity POS Terminal**: Real-time sales terminal with manual and scanner modes.
- **Inventory Management**: Track stock levels, low-stock alerts, and supplier management.
- **Multi-Branch Support**: Manage multiple retail locations from a single dashboard.
- **M-Pesa Integration**: Real-time STK Push payment verification for subscriptions.
- **User RBAC**: Define roles for Managers, Cashiers, and Inventory staff.

### 🤝 For Partner Resellers
- **White-Label Branding**: Customize the platform with your own logo, agency name, and brand colors (sidebar & UI).
- **Custom Domain Support**: Point your own domain to the platform for a seamless brand experience.
- **Private SMTP**: Set up your own email server to send white-labeled notifications to clients.
- **Client Management**: Full control over client provisioning, billing, and plans.

### 🛡️ For Super Admins
- **Global Orchestration**: Manage the entire ecosystem of resellers and tenants.
- **Subscription Management**: Define global pricing plans for both resellers and direct clients.
- **Financial Oversight**: Comprehensive reporting on platform-wide revenue and growth.

## 🛠️ Technology Stack
- **Backend**: Native PHP 8.x with PDO (MySQL/MariaDB)
- **Frontend**: Vanilla CSS3 (Custom Design System), Modern JavaScript (ES6+)
- **Charts**: Chart.js for real-time business analytics
- **Payments**: Safaricom M-Pesa Daraja API Integration (STK Push)

## 📦 Installation & Setup

### Prerequisites
- PHP 8.1 or higher
- MySQL 5.7+ / MariaDB 10.4+
- Apache/Nginx with URL rewriting enabled

### Setup Steps
1. **Clone the repository**:
   ```bash
   git clone https://github.com/yourusername/pos-cloud.git
   ```
2. **Database Configuration**:
   - Create a database named `pos_db`.
   - Import the schema from `database/pos_schema.sql`.
3. **App Configuration**:
   - Copy `config/config.example.php` to `config/config.php`.
   - Update your database credentials and M-Pesa API keys.
4. **Permissions**:
   - Ensure the `uploads/` directory is writable by the server.

## 🔒 Security
- **Data Isolation**: Tenant-level isolation using unique UUIDs.
- **CSRF Protection**: All form submissions are protected against cross-site request forgery.
- **Secure Auth**: Password hashing using `bcrypt`.
- **Administrative Stealth**: No visible "Admin" or "Reseller" tabs on the login page; the system auto-detects roles.

## 📄 License
Commercial License. All rights reserved.

---
Built with ❤️ by the POSCloud Engineering Team.
