# PFE 5G - Architecture Diagram

## System Overview

This is a hybrid **Symfony 7.4 (PHP) + FastAPI (Python)** application for Orange 5G network planning and management. The system manages telecom site deployment workflows, traffic analysis, and capacity planning.

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                              USER INTERFACE                                  │
│                    (Symfony + Twig Templates)                                │
└─────────────────────────────────────────────────────────────────────────────┘
                                        │
                                        ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                           SYMFONY APPLICATION                                 │
│                              (PHP 8.2+)                                      │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  ┌──────────────────────────────────────────────────────────────────────┐  │
│  │                        CONTROLLERS (34)                                │  │
│  ├──────────────────────────────────────────────────────────────────────┤  │
│  │ • AdminDashboardController      • UserDashboardController              │  │
│  │ • SuperuserDashboardController  • AdminWorkflowController              │  │
│  │ • UserIpController             • UserFhController                      │  │
│  │ • UserDeploiementController    • DataImportController                 │  │
│  │ • DataExportController         • WorkflowTicketController             │  │
│  │ • ... (24 more)                                                      │  │
│  └──────────────────────────────────────────────────────────────────────┘  │
│                                      │                                        │
│                                      ▼                                        │
│  ┌──────────────────────────────────────────────────────────────────────┐  │
│  │                        SERVICES (10)                                   │  │
│  ├──────────────────────────────────────────────────────────────────────┤  │
│  │ • WorkflowEngineService      • PythonApiClient                        │  │
│  │ • NotificationService        • IaRecommendationService                │  │
│  │ • DeadlineAlertService       • FhWorkflowService                      │  │
│  │ • TicketWorkflowService      • WorkflowAutoAssigner                   │  │
│  │ • FastApiPortDetector         • KpiSimulator                           │  │
│  └──────────────────────────────────────────────────────────────────────┘  │
│                                      │                                        │
│                                      ▼                                        │
│  ┌──────────────────────────────────────────────────────────────────────┐  │
│  │                        ENTITIES (20)                                   │  │
│  ├──────────────────────────────────────────────────────────────────────┤  │
│  │ • Ticket                      • User                                  │  │
│  │ • TicketTask                  • TicketSite                             │  │
│  │ • TicketComment               • TicketHistory                          │  │
│  │ • Site                        • ProcessedSite                          │  │
│  │ • AnalyseResultat             • AiRecommendation                       │  │
│  │ • Notification                • WorkflowHistory                       │  │
│  │ • ... (9 more)                                                       │  │
│  └──────────────────────────────────────────────────────────────────────┘  │
│                                      │                                        │
└──────────────────────────────────────┼──────────────────────────────────────┘
                                       │
                    ┌──────────────────┼──────────────────┐
                    │                  │                  │
                    ▼                  ▼                  ▼
        ┌───────────────────┐  ┌──────────────┐  ┌──────────────┐
        │   POSTGRESQL      │  │  FASTAPI     │  │  FILE SYSTEM │
        │   DATABASE        │  │  PYTHON API  │  │  (Imports/   │
        │   (Port 5432)     │  │  (Port 8001) │  │   Exports)   │
        └───────────────────┘  └──────────────┘  └──────────────┘
```

## Detailed Component Architecture

### 1. User Interface Layer

**Role-Based Dashboards:**
- **Admin Dashboard**: Full system oversight, statistics, exports
- **Superuser Dashboard**: Site validation, map visualization, KPI monitoring
- **User Dashboard**: Service-specific task management (IP, FH, FO, Deployment)

**Templates Structure:**
```
templates/
├── dashboard/
│   ├── admin/          # Admin-specific views
│   ├── superuser/      # Superuser-specific views
│   └── user/           # Regular user views
├── export/             # Export forms
├── import/             # Import forms
├── reset_password/     # Password reset flows
└── security/           # Login/registration
```

### 2. Controller Layer (Role-Based Access Control)

**Admin Controllers:**
- `AdminDashboardController`: Statistics, site management, exports (CSV/PDF)
- `AdminWorkflowController`: Workflow configuration and management
- `DataImportController`: File upload and processing
- `DataExportController`: Data export functionality

**Superuser Controllers:**
- `SuperuserDashboardController`: Advanced analytics
- `SuperuserMapController`: Geographic site visualization
- `SuperuserValidationController`: Site update validation
- `SuperUserSiteUpdateController`: Site modification requests

**User Controllers (Service-Specific):**
- `UserIpController`: IP engineering tasks
- `UserFhController`: FH (Faisceau Hertzien) workflow
- `UserDeploiementController`: Deployment tasks
- `UserTransmissionController`: Transmission management
- `UserBackhaulController`: Backhaul configuration

### 3. Service Layer (Business Logic)

**WorkflowEngineService** - Core workflow orchestration:
- Manages ticket state transitions
- Auto-assigns tasks based on department/service
- Handles multi-step workflows with conditional branching
- Tracks progress and history

**PythonApiClient** - Python API integration:
- Processes traffic analysis files
- Imports capacity data (FO, FH, Backbone)
- Handles multipart file uploads
- Auto-detects FastAPI port

**IaRecommendationService** - AI-powered recommendations:
- Analyzes site capacity needs
- Suggests infrastructure upgrades
- Provides decision support

**NotificationService** - User notifications:
- Task assignment alerts
- Deadline warnings
- Workflow completion notices

**DeadlineAlertService** - SLA management:
- Monitors ticket deadlines
- Escalates overdue tasks
- Generates reports

### 4. Entity Layer (Data Model)

**Core Entities:**

**Ticket** (Workflow tickets):
- Status: open → in_progress → completed/closed
- Progress tracking (0-100%)
- Multi-site support
- Deadline management
- Workflow type (IP, FH, FO, Deployment)

**TicketTask** (Individual workflow steps):
- Step codes: `engineering_ip`, `capillaire_fo`, `deploiement_fo`, `execution_site`, `validation_finale`
- Decision-based branching (OK, NOK, besoin_fo, swap_routeur)
- Assigned users with service/department

**User** (System users):
- Roles: ROLE_ADMIN, ROLE_SUPERUSER, ROLE_USER
- Service assignment: IP, FO, FH, DEPLOIEMENT
- Department assignment
- Email notifications

**ProcessedSite** (Analyzed site data):
- Traffic metrics (TDD/FDD)
- Capacity analysis
- Classification (critical/normal)
- GPS coordinates
- Service type (FO, FH, SHARED)

**Site** (Physical network sites):
- Name, latitude, longitude
- Status, service type
- Geographic location

### 5. Python FastAPI Backend

**Endpoints:**
```
POST /traiter           # Process traffic/port/link/gps files
POST /capacite/fo       # Import FO capacity data
POST /capacite/fh       # Import FH capacity data
POST /capacite/backbone # Import backbone capacity
POST /capacite/all      # Import all services capacity
GET  /health            # Health check
```

**Processing Modules:**
- `traitement.py` (2997 lines): Traffic analysis, site classification, capacity calculation
- `capacite.py`: Capacity data processing per service
- `etat_sites.py`: Site status analysis

**Analysis Features:**
- Site type detection (TF, TDD, FDD)
- Traffic congestion detection (90% threshold)
- Capacity utilization calculation
- GPS coordinate integration
- Historical data tracking

### 6. Database Layer (PostgreSQL)

**Main Tables:**
- `user` - User accounts and roles
- `ticket` - Workflow tickets
- `ticket_task` - Individual tasks
- `ticket_site` - Ticket-site relationships
- `ticket_history` - Audit trail
- `processed_site` - Analyzed site data
- `site` - Physical site information
- `notification` - User notifications
- `analyse_resultat` - Analysis results
- `ai_recommendation` - AI suggestions

### 7. Workflow State Machine

**IP Engineering Workflow:**
```
engineering_ip
    │
    ├─ OK → execution_site → validation_finale → COMPLETED
    │
    ├─ besoin_fo → capillaire_fo → deploiement_fo → validation_fo → execution_site → ...
    │
    └─ swap_routeur → engineering_ip (loop)
```

**FH Workflow:**
```
STEP_FH_ETUDE_PREREQUIS
    │
    └─ (conditional branching based on analysis)
```

**Deployment Workflow:**
```
execution_site
    │
    ├─ OK → validation_finale → COMPLETED
    │
    └─ NOK → execution_site (correction loop)
```

### 8. Integration Points

**Symfony → Python API:**
- HTTP client with multipart form data
- File upload for Excel/CSV processing
- JSON response handling
- Port auto-detection (8001 default)

**Symfony → PostgreSQL:**
- Doctrine ORM with migrations
- Entity relationships (OneToMany, ManyToMany)
- Query builder for complex reports

**Symfony → File System:**
- Excel import/export (PhpSpreadsheet)
- PDF generation (Dompdf)
- CSV export with UTF-8 BOM

### 9. Security & Authentication

**Symfony Security Bundle:**
- Role-based access control (RBAC)
- Password hashing
- Email verification (Symfonycasts/VerifyEmail)
- Reset password functionality

**User Roles:**
- `ROLE_ADMIN`: Full system access
- `ROLE_SUPERUSER`: Validation and oversight
- `ROLE_USER`: Service-specific tasks

### 10. Deployment Architecture

**Docker Compose:**
```yaml
services:
  database:
    image: postgres:16-alpine
    ports: 5432
    volumes: database_data
```

**Environment Configuration:**
- `.env` - Base configuration
- `.env.local` - Local overrides
- `.env.dev` - Development settings
- `.env.test` - Test settings

**Key Environment Variables:**
- `DATABASE_URL` - PostgreSQL connection
- `PYTHON_API_BASE_URL` - FastAPI endpoint
- `MAILER_DSN` - Email configuration
- `APP_SECRET` - Symfony secret key

## Data Flow Example

**Site Analysis Workflow:**
```
1. User uploads Excel files (traffic, port, link, GPS)
   ↓
2. DataImportController receives files
   ↓
3. PythonApiClient sends to FastAPI /traiter
   ↓
4. Python traitement.py processes data:
   - Classifies sites (TF/TDD/FDD)
   - Calculates capacity utilization
   - Detects congestion (>90%)
   - Integrates GPS coordinates
   ↓
5. Results stored in PostgreSQL (processed_site, analyse_resultat)
   ↓
6. AdminDashboardController displays statistics
   - Total sites, critical sites
   - Service distribution
   - Traffic averages
   ↓
7. Export to CSV/PDF available
```

**Ticket Creation Workflow:**
```
1. User creates ticket via UserIpController
   ↓
2. WorkflowEngineService creates initial task
   ↓
3. Task assigned to IP engineering department
   ↓
4. User completes task with decision (OK/besoin_fo/swap_routeur)
   ↓
5. WorkflowEngineService determines next step:
   - OK → Deployment team
   - besoin_fo → FO team
   - swap_routeur → Back to IP engineering
   ↓
6. NotificationService alerts next assignee
   ↓
7. Process repeats until validation_finale OK
   ↓
8. Ticket marked as completed
```

## Technology Stack Summary

**Backend:**
- Symfony 7.4 (PHP 8.2+)
- FastAPI (Python 3.x)
- PostgreSQL 16

**Frontend:**
- Twig templates
- Symfony UX Turbo
- Stimulus.js
- Asset Mapper

**Libraries:**
- Doctrine ORM
- PhpSpreadsheet (Excel)
- Dompdf (PDF generation)
- Symfony HttpClient

**Development:**
- Docker Compose
- PHPUnit (testing)
- Maker Bundle (code generation)
- Web Profiler (debugging)
