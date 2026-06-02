# 🎯 Think Twice - Inventory & POS System

**A complete, production-ready inventory management and point-of-sale system with a professional, cohesive design.**

![Status](https://img.shields.io/badge/Status-Production%20Ready-success)
![Version](https://img.shields.io/badge/Version-1.0-blue)
![License](https://img.shields.io/badge/License-Proprietary-red)

## ✨ Features

### 🛒 Point of Sale (POS)
- Real-time barcode scanning
- Cart management with quantity adjustment
- Multiple payment methods (Cash, M-Pesa, Split)
- M-Pesa integration with STK push
- Receipt printing and digital storage
- Cart hold/resume for batch processing
- Transaction history and reports

### 📦 Inventory Management
- Complete product catalog
- Category organization
- Stock movement tracking
- Real-time inventory levels
- Goods receiving workflow
- Multi-location warehousing
- Unit of measure definitions
- Price management

### 👥 Supplier Management
- Supplier database
- Contact information storage
- Email and phone validation
- Supplier performance tracking
- Purchase history

### 📊 Reports & Analytics
- Sales reports by date/period
- Inventory stock reports
- Supplier performance analysis
- M-Pesa transaction reports
- Exportable and printable formats
- Real-time data updates

### 🔐 User & Access Management
- Role-based access control (RBAC)
- Custom role creation
- Permission management
- User activity tracking
- Secure authentication

## 🎨 Design

### Modern Dark Theme
- Professional dark interface (`#0f1117`)
- Vibrant green accents (`#00e5a0`)
- High contrast for accessibility
- Responsive design (mobile, tablet, desktop)
- Clean typography and spacing

### Component Library
- Reusable buttons, cards, tables
- Consistent form styling
- Modal dialogs
- Alert notifications
- Badge indicators
- Responsive grid system

### Specialized POS Interface
- Optimized for fast transactions
- Terminal-style monospace typography
- High-visibility elements
- Minimal distraction design

## 📁 Project Structure

```
think-twice/
├── index.php                 # Login page
├── signup.php                # Registration
├── dashboard.php             # Main overview
├── pos.php                   # POS terminal
├── suppliers.php             # Supplier management
├── reports.php               # Analytics
├── itemsandInventory.php     # Inventory hub
│
├── config/
│   ├── db.php               # Database connection
│   ├── authGuard.php        # Auth middleware
│   └── mpesa_token.php      # M-Pesa config
│
├── public/
│   ├── theme.css            # Global design system
│   ├── pos-styles.css       # POS styling
│   └── companyIcon.png      # Logo
│
├── inventory/               # Inventory sub-pages
├── reports/                 # Report sub-pages
├── auth/                    # Authentication
└── sacred/                  # Admin panel
```

## 🚀 Quick Start

### Prerequisites
- PHP 7.4+
- MySQL 5.7+
- Web server (Apache, Nginx)

### Installation

1. **Setup database**
   ```sql
   CREATE DATABASE think_twice;
   ```

2. **Configure environment**
   - Update `config/db.php` with your credentials
   - Update `.env` with M-Pesa credentials

3. **Access the application**
   ```
   http://localhost/think-twice
   ```

4. **Create account**
   - Click "Create one" on login page
   - Fill registration form
   - Admin account gets full access

5. **Start using**
   - Go to Dashboard
   - Navigate to your needed section

## 📚 Documentation

### For Quick Start
→ See **[QUICKSTART.md](QUICKSTART.md)** - Get running in 5 minutes

### For Complete Guide
→ See **[PROJECT_GUIDE.md](PROJECT_GUIDE.md)** - Full documentation

### For Overview
→ See **[COMPLETION_SUMMARY.md](COMPLETION_SUMMARY.md)** - Project details

## 🎯 Key Pages

### User Pages
| Page | Purpose |
|------|---------|
| `index.php` | Login with authentication |
| `signup.php` | User registration |
| `dashboard.php` | Main overview with statistics |

### Operations
| Page | Purpose |
|------|---------|
| `pos.php` | Point of Sale terminal |
| `itemsandInventory.php` | Inventory management hub |
| `suppliers.php` | Supplier management |

### Management
| Page | Purpose |
|------|---------|
| `reports.php` | Analytics and reporting |
| `sacred/admin.php` | User and role management |

## 🎨 Design System

### Colors
```
Primary:    #00e5a0  (Green - Actions)
Background: #0f1117  (Dark)
Surface:    #161b22  (Medium Dark)
Border:     #30363d  (Light Gray)
Text:       #e6edf3  (Light)
Danger:     #ff4d6a  (Red)
Warning:    #ffb830  (Orange)
Info:       #5b8ef0  (Blue)
```

### Components
- **Buttons** - Primary, secondary, danger variants
- **Cards** - Content containers
- **Tables** - Data display
- **Forms** - Input collection
- **Alerts** - User feedback
- **Grids** - Layout system

See `/public/theme.css` for complete component library.

## 🔐 Security Features

- ✅ Password hashing (BCrypt)
- ✅ SQL injection prevention
- ✅ XSS attack prevention
- ✅ Session security
- ✅ Input validation
- ✅ HTTPS ready

## 💰 Payment Integration

### M-Pesa
- STK push for payments
- Status query
- Transaction callbacks
- Fallback manual confirmation
- Transaction history

Configure in `.env`:
```
CONSUMER_KEY=your_key
CONSUMER_SECRET=your_secret
PASSKEY=your_passkey
SHORTCODE=your_shortcode
```

## 📱 Responsive Design

Fully responsive across:
- **Desktop** - Full layout
- **Tablet** - Adjusted columns
- **Mobile** - Single column, touch-friendly

Breakpoint at **768px width**

## 🛠️ Development

### Adding New Pages
1. Create PHP file
2. Include navbar: `<?php include 'navbar.php'; ?>`
3. Link theme: `<link rel="stylesheet" href="/think-twice/public/theme.css">`
4. Use semantic HTML with theme classes
5. Test on mobile

### Using Theme Components

```html
<!-- Page Layout -->
<div class="page-container">
  <div class="page-header">
    <h1 class="page-title">Title</h1>
  </div>
  <div class="page-content">
    <!-- Content -->
  </div>
</div>

<!-- Buttons -->
<button class="btn btn-primary">Primary</button>
<button class="btn btn-danger">Delete</button>

<!-- Forms -->
<div class="form-group">
  <label>Field</label>
  <input type="text">
</div>

<!-- Alerts -->
<div class="alert alert-success">✓ Success!</div>
<div class="alert alert-danger">⚠ Error</div>
```

## 🐛 Troubleshooting

### Login Issues
- Check username/password
- Clear browser cookies
- Verify database connection

### M-Pesa Issues
- Verify API credentials
- Check internet connection
- Review transaction logs

### Display Issues
- Clear browser cache (Ctrl+F5)
- Check file permissions
- Verify CSS file path

For more help, see **PROJECT_GUIDE.md**

## 📊 Statistics

- **Total Files**: 20+
- **CSS Lines**: 1,300+
- **Pages**: 18 (main + sub-pages)
- **Components**: 15+
- **Documentation**: 600+ lines

## ✅ Quality Metrics

- ✅ Clean, maintainable code
- ✅ Security best practices
- ✅ Accessibility considered
- ✅ Responsive design
- ✅ Performance optimized
- ✅ Well documented
- ✅ Production ready

## 🎓 What's Included

### Backend
- PHP 7.4+ compatible
- PDO database abstraction
- Prepared statements
- Session management
- Role-based access control

### Frontend
- Responsive HTML/CSS
- No framework dependencies
- Semantic HTML
- Accessible design
- Cross-browser compatible

### Database
- User authentication
- Role/permission system
- Inventory tracking
- M-Pesa transactions
- Stock movements

## 🚀 Deployment

### Pre-deployment Checklist
- [ ] Update database credentials
- [ ] Configure M-Pesa credentials
- [ ] Set up HTTPS/SSL
- [ ] Create admin account
- [ ] Test all features
- [ ] Backup database
- [ ] Configure backups

### Production Recommendations
- Use HTTPS for all traffic
- Enable database backups
- Monitor transaction logs
- Set up error logging
- Configure access logs
- Regular security updates

## 📞 Support

For issues or questions:
1. Check **QUICKSTART.md** for common tasks
2. Review **PROJECT_GUIDE.md** for detailed docs
3. See troubleshooting sections

## 📄 License

This project is proprietary and confidential.

## 👨‍💼 About

**Think Twice** is a complete inventory and POS system designed for modern businesses. Built with clean code, professional design, and production-ready security.

---

### Quick Links
- 📖 [Quick Start Guide](QUICKSTART.md)
- 📚 [Complete Guide](PROJECT_GUIDE.md)
- 📋 [Project Summary](COMPLETION_SUMMARY.md)

### Status
✅ **Complete** | 🚀 **Production Ready** | 🎯 **Enterprise Grade**

**Version 1.0** — June 2, 2026
