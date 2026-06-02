# Think Twice - Inventory & POS System

A complete end-to-end inventory management and Point-of-Sale (POS) system built with PHP and MySQL. Features real-time stock tracking, M-Pesa payment integration, and comprehensive reporting.

## 🎨 Design System

### Theme
The entire application uses a cohesive **modern dark theme** with a green accent color (`#00e5a0`):

- **Dark backgrounds** for reduced eye strain during long work sessions
- **High contrast** text for accessibility
- **Green accents** for primary actions and highlights
- **Consistent spacing** and typography across all pages
- **Responsive design** that works on desktop and mobile

### Style Files

1. **`/public/theme.css`** - Main theme file
   - Global design tokens (colors, spacing, typography)
   - Reusable component styles (buttons, cards, tables, forms, modals)
   - Utility classes for common patterns
   - Responsive breakpoints

2. **`/public/pos-styles.css`** - POS-specific styling
   - Optimized for fast scanning and transaction processing
   - High-visibility elements
   - Terminal-style aesthetics
   - Monospace typography for clarity

3. **`styles.css`** - Legacy file (imports theme.css for backwards compatibility)

### Color Palette

```
Primary:      #00e5a0  (Accent Green)
Background:   #0f1117  (Dark)
Surface:      #161b22  (Medium Dark)
Border:       #30363d  (Light Gray)
Text:         #e6edf3  (Light)
Text Muted:   #8b949e  (Muted Gray)
Danger:       #ff4d6a  (Red)
Warning:      #ffb830  (Orange)
Success:      #00e5a0  (Green)
Info:         #5b8ef0  (Blue)
```

## 📁 Project Structure

```
think-twice/
├── index.php                    # Login page
├── signup.php                   # User registration
├── dashboard.php                # Main dashboard
├── pos.php                      # Point of Sale system
├── suppliers.php                # Supplier management
├── reports.php                  # Analytics & reports
├── itemsandInventory.php        # Inventory hub
│
├── auth/
│   └── logout.php               # Logout handler
│
├── config/
│   ├── db.php                   # Database connection
│   ├── authGuard.php            # Authentication middleware
│   └── mpesa_token.php          # M-Pesa API token
│
├── inventory/
│   ├── createItem.php           # Add inventory items
│   ├── createCategory.php       # Add categories
│   ├── orderEntry.php           # Purchase requisitions
│   ├── orderEntryApproval.php   # Approve requisitions
│   ├── goodsReceiveNote.php     # Receive goods
│   ├── cycleManagement.php      # Inventory cycles
│   ├── priceSetting.php         # Set item prices
│   ├── wareHousing.php          # Warehouse management
│   ├── unitofMeasure.php        # Unit definitions
│   └── viewSuppliers.php        # View suppliers
│
├── reports/
│   ├── sales_report.php         # Sales analytics
│   ├── stock_movements.php      # Stock history
│   ├── item_listing.php         # Item catalog
│   ├── item_category.php        # Category report
│   ├── suppliers.php            # Supplier analysis
│   ├── user_reports.php         # User activity
│   ├── price_cycles.php         # Pricing history
│   └── item_requisition.php     # Requisition tracking
│
├── sacred/
│   └── admin.php                # Admin panel (roles & permissions)
│
├── public/
│   ├── theme.css                # Global theme
│   ├── pos-styles.css           # POS styles
│   └── companyIcon.png          # Logo
│
├── navbar.php                   # Navigation header
├── mpesa-callback.php           # M-Pesa callback handler
├── .env                         # Environment variables
└── PROJECT_GUIDE.md             # This file
```

## 🔐 Authentication & Authorization

### User Roles

The system uses a **role-based access control (RBAC)** model:

- **Admin** - Full system access, role management
- **Seller** - POS access, view inventory
- **Manager** - Inventory management, reports, suppliers
- **Custom Roles** - Create custom roles with specific permissions

### Permissions

Available permissions:
- `pos` - Point of Sale operations
- `inventory` - Inventory management
- `suppliers` - Supplier management
- `reports` - View reports and analytics
- `roles` - Manage roles and permissions

## 💰 Payment Integration

### M-Pesa Configuration

M-Pesa payment integration is configured in `.env`:

```env
CONSUMER_KEY=<your_key>
CONSUMER_SECRET=<your_secret>
PASSKEY=<your_passkey>
SHORTCODE=<your_shortcode>
```

### Payment Flow

1. **STK Push** - Initiate payment prompt on customer's phone
2. **Query** - Check payment status
3. **Callback** - Receive confirmation from M-Pesa
4. **Fallback** - Manual confirmation if automatic fails

## 🛒 Point of Sale (POS)

### Features

- **Barcode scanning** - Quick item lookup
- **Cart management** - Add, remove, update quantities
- **Payment methods** - Cash, M-Pesa, or split payment
- **Receipt printing** - Print or save receipts
- **Hold carts** - Save carts for later retrieval
- **Transaction history** - View all sales

### Usage

1. Navigate to **Point of Sale**
2. Scan or enter item barcodes
3. Adjust quantities as needed
4. Select payment method
5. Process payment
6. Print/save receipt

## 📊 Inventory Management

### Core Functions

- **Create Items** - Add products to inventory
- **Categories** - Organize items by type
- **Price Setting** - Manage item pricing
- **Stock Movements** - Track inventory changes
- **Goods Receive** - Record incoming stock
- **Cycle Management** - Inventory count cycles
- **Warehousing** - Multi-location management

## 📈 Reports & Analytics

### Available Reports

1. **Sales Report** - Daily/weekly/monthly sales
2. **Inventory Report** - Stock levels and values
3. **Supplier Report** - Supplier performance
4. **Transactions** - M-Pesa transaction history
5. **User Activity** - User actions and login history

All reports are:
- **Exportable** - Print or download
- **Filterable** - By date range, category, supplier
- **Real-time** - Updated automatically

## 🚀 Getting Started

### Prerequisites

- PHP 7.4+
- MySQL 5.7+
- Web server (Apache, Nginx)

### Installation

1. **Clone the repository**
   ```bash
   git clone <repo-url>
   cd think-twice
   ```

2. **Create database**
   ```sql
   CREATE DATABASE think_twice;
   ```

3. **Import schema**
   - Import the database schema from `config/db.php` or SQL file

4. **Configure environment**
   - Update `.env` with your database credentials
   - Add M-Pesa credentials

5. **Set file permissions**
   ```bash
   chmod 755 .
   chmod 644 public/*
   ```

6. **Access the application**
   - Navigate to `http://localhost/think-twice`
   - Login with your credentials

### Default Credentials

Create an admin account:
1. Visit `/signup.php`
2. Create a new account
3. The account will be assigned the "admin" role by default

## 🛠️ Development

### Adding New Pages

1. **Create page file** in appropriate directory
2. **Include navbar** at the top:
   ```php
   <?php include 'navbar.php'; ?>
   ```
3. **Link theme stylesheet**:
   ```html
   <link rel="stylesheet" href="/think-twice/public/theme.css">
   ```
4. **Use semantic HTML** with theme classes
5. **Test on mobile** - ensure responsive design

### Using Theme Components

All components are defined in `/public/theme.css`:

```html
<!-- Page Layout -->
<div class="page-container">
  <div class="page-header">
    <h1 class="page-title">Title</h1>
  </div>
  <div class="page-content">
    <!-- Content here -->
  </div>
</div>

<!-- Cards -->
<div class="card">
  <div class="card-header">
    <div class="card-title">Card Title</div>
  </div>
  <div class="card-body">
    Content here
  </div>
</div>

<!-- Buttons -->
<button class="btn btn-primary">Primary</button>
<button class="btn btn-secondary">Secondary</button>
<button class="btn btn-danger">Danger</button>

<!-- Tables -->
<table class="table">
  <thead>
    <tr>
      <th>Column</th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td>Data</td>
    </tr>
  </tbody>
</table>

<!-- Forms -->
<form>
  <div class="form-group">
    <label>Label</label>
    <input type="text">
  </div>
</form>

<!-- Alerts -->
<div class="alert alert-success">Success message</div>
<div class="alert alert-danger">Error message</div>
<div class="alert alert-warn">Warning message</div>
<div class="alert alert-info">Info message</div>
```

### Utility Classes

```css
.text-center     /* Center text */
.text-muted      /* Muted text color */
.text-primary    /* Primary color text */
.font-mono       /* Monospace font */
.font-bold       /* Bold text */
.mt, .mb, .ml, .mr  /* Margin utilities */
.gap-sm, .gap-md, .gap-lg  /* Gap utilities */
.flex            /* Flexbox */
.flex-between    /* Flex with space-between */
.grid-2, .grid-3, .grid-4  /* Grid layouts */
```

## 📱 Responsive Design

The application is fully responsive:

- **Desktop** - Full layout with sidebars
- **Tablet** - Adjusted column counts
- **Mobile** - Single column, touch-friendly buttons

Breakpoint:
- **Mobile** - Below 768px width

## 🔒 Security

### Best Practices Implemented

- **Password hashing** - BCrypt (PASSWORD_DEFAULT)
- **SQL injection prevention** - Prepared statements
- **XSS prevention** - htmlspecialchars() on all output
- **Session security** - session_regenerate_id() after login
- **HTTPS** - All production traffic should be encrypted

### User Input Validation

All user inputs are:
- **Trimmed** - Remove leading/trailing whitespace
- **Validated** - Check format and length
- **Sanitized** - HTML-escaped before display
- **Type-checked** - Verify expected data types

## 🐛 Troubleshooting

### Common Issues

**"Database connection error"**
- Check `.env` credentials
- Verify MySQL service is running
- Ensure database exists

**"Session not found"**
- Verify `session_start()` is called before output
- Check PHP session handler configuration
- Clear browser cookies and try again

**"Stylesheet not loading"**
- Verify file path is correct
- Check file permissions
- Hard refresh browser (Ctrl+F5)

**"M-Pesa payment fails"**
- Check M-Pesa credentials in `.env`
- Verify internet connection
- Check M-Pesa API status
- Review transaction logs

## 📚 Additional Resources

- **PHP Documentation** - https://www.php.net/docs.php
- **MySQL Documentation** - https://dev.mysql.com/doc/
- **M-Pesa Daraja** - https://developer.safaricom.co.ke/

## 📄 License

This project is proprietary and confidential.

## 👨‍💼 Support

For issues or questions, contact the development team.

---

**Version:** 1.0  
**Last Updated:** June 2, 2026  
**Status:** Production Ready
