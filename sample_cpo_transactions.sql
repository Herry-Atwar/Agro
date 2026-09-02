-- Sample CPO Stock Transactions Data
-- This will create realistic sample data for testing

-- Make sure storage_tanks table has data first
-- Assuming tank_id 1, 2, 3 exist

-- Clear existing sample data (optional)
-- DELETE FROM cpo_stock_transactions WHERE notes LIKE '%Sample%';

-- Sample transactions for the last 30 days
-- Tank 1: Production receipts and sales

-- Week 1: Production receipts
INSERT INTO cpo_stock_transactions (storage_tank_id, transaction_type, transaction_date, quantity_kg, reference_no, notes, created_by) VALUES
(1, 'in', DATE_SUB(CURDATE(), INTERVAL 28 DAY), 15000.00, 'PROD-001', 'Sample: Production receipt from Mill', 'admin'),
(1, 'in', DATE_SUB(CURDATE(), INTERVAL 27 DAY), 18500.00, 'PROD-002', 'Sample: Production receipt from Mill', 'admin'),
(1, 'in', DATE_SUB(CURDATE(), INTERVAL 26 DAY), 16200.00, 'PROD-003', 'Sample: Production receipt from Mill', 'admin'),

-- Week 1: Sales/Dispatch
(1, 'out', DATE_SUB(CURDATE(), INTERVAL 25 DAY), 20000.00, 'SALE-001', 'Sample: Sales to Customer A', 'admin'),
(1, 'out', DATE_SUB(CURDATE(), INTERVAL 24 DAY), 15000.00, 'SALE-002', 'Sample: Sales to Customer B', 'admin'),

-- Week 2: Production receipts
(1, 'in', DATE_SUB(CURDATE(), INTERVAL 21 DAY), 17800.00, 'PROD-004', 'Sample: Production receipt from Mill', 'admin'),
(1, 'in', DATE_SUB(CURDATE(), INTERVAL 20 DAY), 19200.00, 'PROD-005', 'Sample: Production receipt from Mill', 'admin'),
(1, 'in', DATE_SUB(CURDATE(), INTERVAL 19 DAY), 16500.00, 'PROD-006', 'Sample: Production receipt from Mill', 'admin'),

-- Week 2: Sales
(1, 'out', DATE_SUB(CURDATE(), INTERVAL 18 DAY), 25000.00, 'SALE-003', 'Sample: Sales to Customer C', 'admin'),

-- Week 3: Production and transfers
(1, 'in', DATE_SUB(CURDATE(), INTERVAL 14 DAY), 18900.00, 'PROD-007', 'Sample: Production receipt from Mill', 'admin'),
(1, 'in', DATE_SUB(CURDATE(), INTERVAL 13 DAY), 17300.00, 'PROD-008', 'Sample: Production receipt from Mill', 'admin'),
(1, 'transfer', DATE_SUB(CURDATE(), INTERVAL 12 DAY), -10000.00, 'TRF-001', 'Sample: Transfer to Tank 2', 'admin'),

-- Week 3: Sales
(1, 'out', DATE_SUB(CURDATE(), INTERVAL 11 DAY), 18000.00, 'SALE-004', 'Sample: Sales to Customer D', 'admin'),

-- Week 4: Recent transactions
(1, 'in', DATE_SUB(CURDATE(), INTERVAL 7 DAY), 19500.00, 'PROD-009', 'Sample: Production receipt from Mill', 'admin'),
(1, 'in', DATE_SUB(CURDATE(), INTERVAL 6 DAY), 18200.00, 'PROD-010', 'Sample: Production receipt from Mill', 'admin'),
(1, 'out', DATE_SUB(CURDATE(), INTERVAL 5 DAY), 22000.00, 'SALE-005', 'Sample: Sales to Customer E', 'admin'),
(1, 'in', DATE_SUB(CURDATE(), INTERVAL 3 DAY), 17600.00, 'PROD-011', 'Sample: Production receipt from Mill', 'admin'),
(1, 'in', DATE_SUB(CURDATE(), INTERVAL 2 DAY), 19800.00, 'PROD-012', 'Sample: Production receipt from Mill', 'admin'),
(1, 'out', DATE_SUB(CURDATE(), INTERVAL 1 DAY), 15000.00, 'SALE-006', 'Sample: Sales to Customer F', 'admin'),
(1, 'in', CURDATE(), 18400.00, 'PROD-013', 'Sample: Production receipt from Mill', 'admin');

-- Tank 2: Transactions
INSERT INTO cpo_stock_transactions (storage_tank_id, transaction_type, transaction_date, quantity_kg, reference_no, notes, created_by) VALUES
(2, 'in', DATE_SUB(CURDATE(), INTERVAL 25 DAY), 12000.00, 'PROD-101', 'Sample: Production receipt', 'admin'),
(2, 'in', DATE_SUB(CURDATE(), INTERVAL 23 DAY), 14500.00, 'PROD-102', 'Sample: Production receipt', 'admin'),
(2, 'transfer', DATE_SUB(CURDATE(), INTERVAL 12 DAY), 10000.00, 'TRF-001', 'Sample: Transfer from Tank 1', 'admin'),
(2, 'out', DATE_SUB(CURDATE(), INTERVAL 10 DAY), 18000.00, 'SALE-101', 'Sample: Sales dispatch', 'admin'),
(2, 'in', DATE_SUB(CURDATE(), INTERVAL 8 DAY), 15800.00, 'PROD-103', 'Sample: Production receipt', 'admin'),
(2, 'in', DATE_SUB(CURDATE(), INTERVAL 5 DAY), 16200.00, 'PROD-104', 'Sample: Production receipt', 'admin'),
(2, 'out', DATE_SUB(CURDATE(), INTERVAL 3 DAY), 12000.00, 'SALE-102', 'Sample: Sales dispatch', 'admin'),
(2, 'in', DATE_SUB(CURDATE(), INTERVAL 1 DAY), 14900.00, 'PROD-105', 'Sample: Production receipt', 'admin');

-- Tank 3: Transactions
INSERT INTO cpo_stock_transactions (storage_tank_id, transaction_type, transaction_date, quantity_kg, reference_no, notes, created_by) VALUES
(3, 'in', DATE_SUB(CURDATE(), INTERVAL 20 DAY), 10000.00, 'PROD-201', 'Sample: Production receipt', 'admin'),
(3, 'in', DATE_SUB(CURDATE(), INTERVAL 18 DAY), 11500.00, 'PROD-202', 'Sample: Production receipt', 'admin'),
(3, 'out', DATE_SUB(CURDATE(), INTERVAL 15 DAY), 8000.00, 'SALE-201', 'Sample: Sales dispatch', 'admin'),
(3, 'in', DATE_SUB(CURDATE(), INTERVAL 12 DAY), 12300.00, 'PROD-203', 'Sample: Production receipt', 'admin'),
(3, 'adjustment', DATE_SUB(CURDATE(), INTERVAL 10 DAY), -500.00, 'ADJ-001', 'Sample: Stock adjustment - spillage', 'admin'),
(3, 'in', DATE_SUB(CURDATE(), INTERVAL 7 DAY), 13200.00, 'PROD-204', 'Sample: Production receipt', 'admin'),
(3, 'out', DATE_SUB(CURDATE(), INTERVAL 4 DAY), 10000.00, 'SALE-202', 'Sample: Sales dispatch', 'admin'),
(3, 'in', DATE_SUB(CURDATE(), INTERVAL 2 DAY), 11800.00, 'PROD-205', 'Sample: Production receipt', 'admin');

-- Some adjustments for quality issues
INSERT INTO cpo_stock_transactions (storage_tank_id, transaction_type, transaction_date, quantity_kg, reference_no, notes, created_by) VALUES
(1, 'adjustment', DATE_SUB(CURDATE(), INTERVAL 15 DAY), -300.00, 'ADJ-002', 'Sample: Quality adjustment', 'admin'),
(2, 'adjustment', DATE_SUB(CURDATE(), INTERVAL 8 DAY), 200.00, 'ADJ-003', 'Sample: Correction - measurement error', 'admin');

-- Summary of sample data:
-- Tank 1: Multiple in/out transactions, 1 transfer out, 1 adjustment
-- Tank 2: Multiple in/out transactions, 1 transfer in, 1 adjustment
-- Tank 3: Multiple in/out transactions, 1 adjustment
-- Total: ~40 transactions over 30 days

-- To verify the data:
-- SELECT storage_tank_id, transaction_type, COUNT(*) as count, SUM(quantity_kg) as total_kg
-- FROM cpo_stock_transactions
-- WHERE notes LIKE '%Sample%'
-- GROUP BY storage_tank_id, transaction_type;

-- To check current stock per tank:
-- SELECT * FROM vw_tank_stock_summary;

-- Made with Bob
