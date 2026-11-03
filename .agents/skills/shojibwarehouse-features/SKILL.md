---
name: mohimaa-features
description: "Core business features and domain guidelines for MohimaaWarehouse (Single-vendor E-Commerce platform). Activates when working on Client Accounts/Ledger, Products/Catalog, Order Management, or Bangladeshi Courier Integrations (Pathao, SteadFast, RedX)."
license: MIT
metadata:
  author: laravel
---

# MohimaaWarehouse Core Features & Business Logic

This skill outlines the core business domains, architectures, and integration flows of MohimaaWarehouse.

## When to Apply

Activate this skill when working on:
- Single-vendor e-commerce domain models and application logic.
- Courier integrations and customer fraud verification checks.
- Product Catalog, specifications, variants, and stock management.
- Orders, order item transformations, and status flows.
- Client ledger, accounts, and double-entry transaction posting.

---

## 1. Single-Vendor Architecture & Data Scoping

MohimaaWarehouse is built as a single-vendor e-commerce platform.

- **No Vendor ID / Tenancy**: Do NOT use `vendor_id` on any model (such as `Client`, `Product`, `Order`, `Account`, `Transaction`, `StoreSetup`, `Banner`, etc.).
- **Global Data Access**: All records belong directly to the single warehouse/store instance without multi-tenancy or vendor data isolation overhead.

---

## 2. Bangladeshi Courier Integrations & Fraud Checker

The application integrates with Bangladeshi couriers (Pathao, SteadFast, RedX, etc.) to fetch delivery success/return histories for customers based on their phone numbers to prevent fraudulent orders.

### Core Classes & Services
- **`ClientStatsService`**: Merges local delivery statistics (calculated using store orders with statuses `delivered` or `cancelled`) with third-party courier statistics stored on the client.
- **`SyncClientCourierStatsJob`**: Handles background synchronization of third-party statistics. It is dispatched automatically on:
  - Client creation (if a phone number exists).
  - Client update (if the phone number changes).
  - Manual request to check stats.
- **`CourierFraudCheckerCredential`**: Stores API credentials for the fraud check engines configured for the store.

### Computing Global Success Rates
Global success rates are computed dynamically using:
1. **Local history**: The count of local delivered vs. cancelled orders.
2. **Third-party history**: E.g., Pathao's rating and SteadFast/RedX delivery success/cancel ratios.
3. Average calculations from these sources to determine a badge status (e.g. `85% Success Rate`) and color classes (e.g. `bg-success` for low-risk, `bg-danger` for high-risk).

---

## 3. Client Ledger & Accounting (Double Entry)

Clients have financial balances that must be updated and tracked cleanly.

- **Ledger Model**: Each transaction maps to a `Transaction` model.
- **Credit / Debit**:
  - Outgoing or credit operations increase or decrease the balance.
  - Transactions specify transaction categories (e.g. via `TransactionCategoryEnum`) and map back to a payment method or `Account` (e.g. Cash, Mobile Money, Bank Account).
- **Client Balance**: Maintained on the `Client` table (`balance` column) and updated consistently on transaction edits or creations.

---

## 4. Product Catalog & Inventory Cataloging

The catalog manages inventory structure with variants and properties.

- **Properties**: Supported through `Attribute` (e.g. Color, Size) and `AttributeValue`.
- **Variants**: Managed via `ProductVariant` which maps to products and links attributes using `ProductVariantAttributeValue`.
- **Specifications**: Structured specs are stored through `ProductSpecification` and `ProductSpecificationValue`.
- **Stock Movement**: Tracked using `StockMovement` and `StockMovementService` whenever stock changes (e.g., from adjustments, purchases, or sales).

---

## 5. Orders & Status Flow

- **Statuses**: Tracked via `OrderStatusEnum` (e.g., `PENDING`, `PROCESSING`, `DELIVERED`, `CANCELLED`, `RETURNED`).
- **History**: Status transitions are logged in `OrderStatusHistory`.


