
// MongoDB Empty Seed Script for inventory_system (Production Deployment)
// Run using: mongosh --file seed_mongodb_empty.js

const conn = connect("mongodb+srv://unique_db_user:<PASSWORD>@cluster0.rqqagpk.mongodb.net/inventory_system");
const db = conn.getDB("inventory_system");

print("🚀 Starting clean deployment setup...");

// Drop existing collections if they exist
const collections = [
  "borrowable_catalog",
  "borrowable_models",
  "categories",
  "equipment_requests",
  "inventory_delete_log",
  "inventory_items",
  "inventory_scans",
  "lost_damaged_log",
  "models",
  "notifications",
  "request_allocations",
  "returned_hold",
  "returned_queue",
  "users",
  "user_borrows",
  "user_limits"
];

collections.forEach(coll => {
  if (db.getCollectionNames().includes(coll)) {
    db.getCollection(coll).drop();
    print("🗑️ Dropped existing collection: " + coll);
  }
});

// Recreate collections (empty, ready for production)
collections.forEach(coll => {
  db.createCollection(coll);
  print("✅ Created empty collection: " + coll);
});

print("\n✅ Database 'inventory_system' initialized with empty collections, ready for deployment.");
