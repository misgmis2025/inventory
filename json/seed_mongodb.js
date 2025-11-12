
// MongoDB Seed Script for inventory_system with Sample Data
// Run this using: mongosh --file seed_mongodb.js

const conn = connect("mongodb+srv://unique_db_user:<PASSWORD>@cluster0.rqqagpk.mongodb.net/inventory_system");
const db = conn.getDB("inventory_system");

// 1) borrowable_catalog
db.getCollection("borrowable_catalog").insertMany([{
  _id: ObjectId(),
  id: NumberInt(1),
  model_name: "Canon EOS M50 Camera",
  category: "Photography",
  active: true,
  per_user_limit: NumberInt(1),
  global_limit: NumberInt(10),
  created_at: ISODate("2025-10-25T00:00:00Z")
}]);

// 2) borrowable_models
db.getCollection("borrowable_models").insertMany([{
  _id: ObjectId(),
  id: NumberInt(1),
  model_name: "Dell Latitude 7420",
  category: "Computers",
  active: true,
  pool_qty: NumberInt(5),
  borrow_limit: NumberInt(1),
  created_at: ISODate("2025-10-25T00:00:00Z")
}]);

// 3) categories
db.getCollection("categories").insertMany([{
  _id: ObjectId(),
  id: NumberInt(1),
  name: "Electronics",
  description: "Devices and electronic equipment used by departments",
  active: true,
  created_at: ISODate("2025-10-25T00:00:00Z")
}]);

// 4) equipment_requests
db.getCollection("equipment_requests").insertMany([{
  _id: ObjectId(),
  id: NumberInt(1),
  username: "jdoe",
  item_name: "Multimedia Projector - Epson XGA",
  quantity: NumberInt(1),
  details: "Needed for 2-hour lecture in Auditorium A",
  status: "Pending",
  created_at: ISODate("2025-10-25T00:00:00Z"),
  approved_at: null,
  rejected_at: null,
  borrowed_at: null,
  returned_at: null
}]);

// 5) inventory_delete_log
db.getCollection("inventory_delete_log").insertMany([{
  _id: ObjectId(),
  id: NumberInt(1),
  item_id: NumberInt(101),
  deleted_by: "am_admin",
  deleted_at: ISODate("2025-10-25T00:00:00Z"),
  reason: "Obsolete model - beyond repair",
  item_name: "Old CRT Monitor",
  model: "Generic-CRT-2005",
  category: "Electronics",
  quantity: NumberInt(2),
  status: "Removed"
}]);

// 6) inventory_items
db.getCollection("inventory_items").insertMany([{
  _id: ObjectId(),
  id: NumberInt(101),
  item_name: "Lenovo ThinkPad T14",
  category: "Computers",
  model: "T14-Gen2",
  quantity: NumberInt(10),
  location: "IT Dept - Room 201",
  condition: "Good",
  status: "Available",
  date_acquired: ISODate("2023-08-15T00:00:00Z"),
  remarks: "Assigned to pool for faculty use",
  created_at: ISODate("2025-10-25T00:00:00Z"),
  updated_at: ISODate("2025-10-25T00:00:00Z"),
  borrowable: true
}]);

// 7) inventory_scans
db.getCollection("inventory_scans").insertMany([{
  _id: ObjectId(),
  id: NumberInt(1),
  model_id: NumberInt(101),
  item_name: "Lenovo ThinkPad T14",
  status: "Scanned",
  form_type: "Routine Audit",
  room: "IT Dept - Room 201",
  generated_date: ISODate("2025-10-24T14:00:00Z"),
  scanned_at: ISODate("2025-10-25T00:00:00Z"),
  scanned_by: "inventory_officer",
  raw_qr: "QR|LENOVO|T14|101"
}]);

// 8) lost_damaged_log
db.getCollection("lost_damaged_log").insertMany([{
  _id: ObjectId(),
  id: NumberInt(1),
  model_id: NumberInt(101),
  username: "asmith",
  action: "Under Maintenance",
  noted_at: ISODate("2025-10-25T00:00:00Z"),
  created_at: ISODate("2025-10-25T00:00:00Z"),
  notes: "Screen flickering intermittently; sent for repair"
}]);

// 9) models
db.getCollection("models").insertMany([{
  _id: ObjectId(),
  id: NumberInt(1),
  category_id: NumberInt(1),
  name: "ThinkPad T14 Series",
  created_at: ISODate("2025-10-25T00:00:00Z")
}]);

// 10) notifications
db.getCollection("notifications").insertMany([{
  _id: ObjectId(),
  id: NumberInt(1),
  type: "request_created",
  username: "jdoe",
  request_id: NumberInt(1),
  audience: "admin",
  seen: false,
  created_at: ISODate("2025-10-25T00:00:00Z")
}]);

// 11) request_allocations
db.getCollection("request_allocations").insertMany([{
  _id: ObjectId(),
  id: NumberInt(1),
  request_id: NumberInt(1),
  borrow_id: NumberInt(201),
  allocated_at: ISODate("2025-10-25T00:00:00Z")
}]);

// 12) returned_hold
db.getCollection("returned_hold").insertMany([{
  _id: ObjectId(),
  id: NumberInt(1),
  model_id: NumberInt(101),
  model_name: "ThinkPad T14",
  category: "Computers",
  source_qid: NumberInt(201),
  held_at: ISODate("2025-10-25T00:00:00Z")
}]);

// 13) returned_queue
db.getCollection("returned_queue").insertMany([{
  _id: ObjectId(),
  id: NumberInt(1),
  model_id: NumberInt(101),
  source: "Returned",
  queued_at: ISODate("2025-10-25T00:00:00Z"),
  processed_at: null,
  processed_by: null,
  action: "added_to_borrowable",
  notes: "Returned in good condition"
}]);

// 14) users
db.getCollection("users").insertMany([{
  _id: ObjectId(),
  id: NumberInt(1),
  username: "admin",
  password: "hashed_password_placeholder",
  usertype: "admin",
  full_name: "System Administrator"
}]);

// 15) user_borrows
db.getCollection("user_borrows").insertMany([{
  _id: ObjectId(),
  id: NumberInt(201),
  username: "jdoe",
  model_id: NumberInt(101),
  borrowed_at: ISODate("2025-10-25T00:00:00Z"),
  returned_at: null,
  status: "Borrowed"
}]);

// 16) user_limits
db.getCollection("user_limits").insertMany([{
  _id: ObjectId(),
  username: "jdoe",
  max_active: NumberInt(3)
}]);

print("✅ Seeding complete — inserted one sample document into each collection.");
