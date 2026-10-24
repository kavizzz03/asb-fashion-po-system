-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Oct 24, 2026 at 07:11 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `return_qc`
--

-- --------------------------------------------------------

--
-- Table structure for table `branches`
--

CREATE TABLE `branches` (
  `branch_id` int(11) NOT NULL,
  `branch_code` varchar(20) NOT NULL,
  `branch_name` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `floors`
--

CREATE TABLE `floors` (
  `floor_id` int(11) NOT NULL,
  `floor_name` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `invoice_items`
--

CREATE TABLE `invoice_items` (
  `invoice_item_id` int(11) NOT NULL,
  `invoice_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `received_qty` int(11) NOT NULL DEFAULT 0,
  `checked_sample_qty` int(11) NOT NULL DEFAULT 0,
  `defect_qty` int(11) NOT NULL DEFAULT 0,
  `return_qty` int(11) NOT NULL DEFAULT 0,
  `status` enum('PASS','FAIL') NOT NULL DEFAULT 'PASS'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `items`
--

CREATE TABLE `items` (
  `item_id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `item_name` varchar(150) NOT NULL,
  `item_code` varchar(50) NOT NULL,
  `system_id` varchar(50) DEFAULT NULL,
  `quantity` int(11) NOT NULL DEFAULT 0,
  `cost_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `selling_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `item_return_reasons`
--

CREATE TABLE `item_return_reasons` (
  `id` int(11) NOT NULL,
  `invoice_item_id` int(11) NOT NULL,
  `reason_id` int(11) NOT NULL,
  `return_qty` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `master_tabs`
--

CREATE TABLE `master_tabs` (
  `tab_id` int(11) NOT NULL,
  `tab_name` varchar(100) NOT NULL,
  `show_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `tab_url` varchar(255) NOT NULL,
  `tab_icon` varchar(50) DEFAULT 'fas fa-link',
  `sort_order` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `qc_audit_log`
--

CREATE TABLE `qc_audit_log` (
  `log_id` int(11) NOT NULL,
  `record_id` int(11) DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `field_name` varchar(50) DEFAULT NULL,
  `old_value` text DEFAULT NULL,
  `new_value` text DEFAULT NULL,
  `changed_by` varchar(50) DEFAULT NULL,
  `changed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `ip_address` varchar(45) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `qc_damage_items`
--

CREATE TABLE `qc_damage_items` (
  `item_id` int(11) NOT NULL,
  `record_id` int(11) DEFAULT NULL,
  `item_code` varchar(50) NOT NULL,
  `item_name` varchar(200) DEFAULT NULL,
  `quantity` int(11) NOT NULL,
  `unit_cost` decimal(15,2) NOT NULL DEFAULT 0.00,
  `total_cost` decimal(15,2) GENERATED ALWAYS AS (`quantity` * `unit_cost`) STORED,
  `defect_type` varchar(100) DEFAULT NULL,
  `defect_description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Triggers `qc_damage_items`
--
DELIMITER $$
CREATE TRIGGER `trg_calc_total_amount` AFTER INSERT ON `qc_damage_items` FOR EACH ROW BEGIN
    DECLARE v_total DECIMAL(15,2);
    
    SELECT SUM(total_cost) INTO v_total 
    FROM qc_damage_items 
    WHERE record_id = NEW.record_id;
    
    UPDATE qc_damage_main 
    SET total_amount = IFNULL(v_total, 0)
    WHERE record_id = NEW.record_id;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_update_total_amount` AFTER DELETE ON `qc_damage_items` FOR EACH ROW BEGIN
    DECLARE v_total DECIMAL(15,2);
    
    SELECT SUM(total_cost) INTO v_total 
    FROM qc_damage_items 
    WHERE record_id = OLD.record_id;
    
    UPDATE qc_damage_main 
    SET total_amount = IFNULL(v_total, 0)
    WHERE record_id = OLD.record_id;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `qc_damage_main`
--

CREATE TABLE `qc_damage_main` (
  `record_id` int(11) NOT NULL,
  `record_date` date NOT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `invoice_number` varchar(50) DEFAULT NULL,
  `reference_number` varchar(50) DEFAULT NULL,
  `doc_number` varchar(50) DEFAULT NULL,
  `mode_id` int(11) DEFAULT NULL,
  `reason_id` int(11) DEFAULT NULL,
  `added_by_user` varchar(50) DEFAULT NULL,
  `parcel_count` int(11) DEFAULT 1,
  `added_time` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_informed` tinyint(1) DEFAULT 0,
  `informed_by_user` varchar(50) DEFAULT NULL,
  `informed_datetime` datetime DEFAULT NULL,
  `is_store_received` tinyint(1) DEFAULT 0,
  `store_user` varchar(50) DEFAULT NULL,
  `store_datetime` datetime DEFAULT NULL,
  `is_gate_cleared` tinyint(1) DEFAULT 0,
  `gate_user` varchar(50) DEFAULT NULL,
  `gate_datetime` datetime DEFAULT NULL,
  `is_handover_complete` tinyint(1) DEFAULT 0,
  `number_of_bags` int(11) NOT NULL DEFAULT 0,
  `handover_user` varchar(50) DEFAULT NULL,
  `handover_datetime` datetime DEFAULT NULL,
  `is_active_cart` tinyint(1) DEFAULT 1,
  `print_count` int(11) DEFAULT 0,
  `assigned_task_id` int(11) DEFAULT NULL,
  `total_amount` decimal(15,2) DEFAULT 0.00,
  `remarks` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `qc_item_images`
--

CREATE TABLE `qc_item_images` (
  `image_id` int(11) NOT NULL,
  `record_id` int(11) DEFAULT NULL,
  `image_path` varchar(500) NOT NULL,
  `image_name` varchar(200) DEFAULT NULL,
  `image_size` int(11) DEFAULT NULL,
  `image_type` varchar(50) DEFAULT NULL,
  `uploaded_by` varchar(50) DEFAULT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_primary` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `qc_modes`
--

CREATE TABLE `qc_modes` (
  `mode_id` int(11) NOT NULL,
  `mode_name` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `qc_return_reasons`
--

CREATE TABLE `qc_return_reasons` (
  `reason_id` int(11) NOT NULL,
  `reason_code` varchar(20) DEFAULT NULL,
  `reason_text` varchar(255) NOT NULL,
  `category` varchar(50) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `sort_order` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `qc_tasks`
--

CREATE TABLE `qc_tasks` (
  `task_id` int(11) NOT NULL,
  `record_id` int(11) DEFAULT NULL,
  `assigned_to` varchar(50) DEFAULT NULL,
  `assigned_by` varchar(50) DEFAULT NULL,
  `assigned_date` datetime DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `priority` enum('Low','Medium','High','Urgent') DEFAULT 'Medium',
  `status` enum('Pending','In Progress','Completed','Cancelled') DEFAULT 'Pending',
  `completed_date` datetime DEFAULT NULL,
  `remarks` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `return_reasons`
--

CREATE TABLE `return_reasons` (
  `reason_id` int(11) NOT NULL,
  `reason_text` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `suppliers`
--

CREATE TABLE `suppliers` (
  `supplier_id` int(11) NOT NULL,
  `supplier_name` varchar(100) NOT NULL,
  `system_id` varchar(50) DEFAULT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `land_number` varchar(20) DEFAULT NULL,
  `fax_number` varchar(20) DEFAULT NULL,
  `contact_person` varchar(100) DEFAULT NULL,
  `whatsapp` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `status` varchar(50) DEFAULT 'New 01'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `suppliers`
--

INSERT INTO `suppliers` (`supplier_id`, `supplier_name`, `system_id`, `contact_number`, `land_number`, `fax_number`, `contact_person`, `whatsapp`, `email`, `address`, `status`) VALUES
(38, 'CENTER POINT', '0030', '', NULL, NULL, NULL, NULL, '', '0', 'Active'),
(385, 'DMAXX', '0377', '94773037601', NULL, NULL, NULL, NULL, '', 'NO.19,KEERTHI SRI RAJASINGHE MW,KANDY. KANDY', 'Active'),
(425, 'D.K FASHION', '0417', '94719184283', NULL, NULL, NULL, NULL, '', 'HANWELLA .', 'Active'),
(456, 'DRESS HILL GARMENT', '0448', '', NULL, NULL, NULL, NULL, '', '. .', 'Active'),
(530, 'A.C.B.WELIGAMA', '0522', '94773993296', NULL, NULL, NULL, NULL, '', 'HETTI VEEDIYA WELIGAMA', 'Active'),
(534, 'A.C.M SAIL', '0526', '', NULL, NULL, NULL, NULL, '', '0', 'Active'),
(558, '4 WAY GARMENT', NULL, '94726240899', NULL, NULL, NULL, NULL, '', 'NO.82,NAGODA,KALUTARA KALUTARA', 'Active'),
(750, 'A-Z ENTERPRISES', '0742', '94785528901', NULL, NULL, NULL, NULL, '', 'NO:38-B10,PERERA PLACE KAWDANA,DEHIWALA', 'Active'),
(787, '1ST CHOICE', '0779', '', NULL, NULL, NULL, NULL, '', '0', 'Active'),
(807, 'A.A.FERNANDO', '0799', '94723652711', NULL, NULL, NULL, NULL, '', 'SIRI NIWASA MAWATHA KALUTARA', 'Active'),
(1364, '1ST CHOICE - MATERIAL', '1356', '94112441666', NULL, NULL, NULL, NULL, '', 'KEYZER STREET COLOMBO 11', 'Active'),
(1370, 'RANLI (SAMANTHA GALLE)', '1362', '94777306370', NULL, NULL, NULL, NULL, '', 'WATAREKA ROAD, BOPE GALLE', 'Active'),
(1471, 'A.J TRADERS', '1463', '', NULL, NULL, NULL, NULL, '.', 'J.B SUPER MARKET COLOMBO 11 . .', 'Active'),
(1624, 'A.D.S. COSMETICS', '1616', '94112331353', NULL, NULL, NULL, NULL, '', 'COLOMBO -11', 'Active'),
(1641, 'A.S.P GARMENT', '1633', '94777579113', NULL, NULL, NULL, NULL, '', '181/14, TISSA ROAD, GALAVILA WATHTHA, HOMAGAMA', 'Active'),
(1676, 'SELLERS APPAREL(PVT)LTD', '1668', '94324934128', NULL, NULL, NULL, NULL, '', '', 'Active'),
(1728, 'A.K SISIRA WASANTHA', '1720', '94771918039', NULL, NULL, NULL, NULL, '', '', 'Active'),
(1851, '123', '1843', '', NULL, NULL, NULL, NULL, '', '', 'Active'),
(1987, '4OUR-FOUR', '1982', '', NULL, NULL, NULL, NULL, '', '', 'Active'),
(2021, 'A.A RATHNAYAKA', '2016', '', NULL, NULL, NULL, NULL, '', 'MOUNT LAVINIYA', 'Active'),
(2034, 'A & D FASHION', '2029', '', NULL, NULL, NULL, NULL, '', '', 'Active'),
(2305, 'CRISTINA', '2303', '', NULL, NULL, NULL, NULL, '', 'KARAPITIYA GALLE', 'Active'),
(2628, 'IROMA GUNARATHNE', '2626', '', NULL, NULL, NULL, NULL, 'iromagunaratne@gmail.com', '10/9, VIDYAKARA MAWATHA, MAHARAGAMA', 'Active'),
(2643, 'THE BEAR KINGDOM', '2640', '94763038533', NULL, NULL, NULL, NULL, 'sanjalccjayasinghe2@gmai.com', 'NO.362 E/1, NEW KANDY ROAD, DELGODA', 'Active'),
(2652, 'HOTLOOK JEANS', '', '94777369921', NULL, NULL, NULL, NULL, 'kaveen.kanagarajh@gmail.com', '92-2/20 kezer street,Colombo 11', 'Active'),
(2653, 'test supplier', '', '94740890730', '94740890730', '', 'Mc Colombo', '94740890730', 'colombomc09@gmail.com', '12/d 2 thunna hanella ', 'Active');

-- --------------------------------------------------------

--
-- Table structure for table `supplier_invoices`
--

CREATE TABLE `supplier_invoices` (
  `invoice_id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `invoice_number` varchar(50) NOT NULL,
  `invoice_date` date NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `floor_id` int(11) NOT NULL,
  `checked_date` date NOT NULL,
  `checker_name` varchar(100) NOT NULL,
  `added_by` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(100) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `role` enum('admin','manager','user') DEFAULT 'user',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_tabs`
--

CREATE TABLE `user_tabs` (
  `assignment_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `tab_id` int(11) DEFAULT NULL,
  `tab_name` varchar(100) NOT NULL,
  `show_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `tab_url` varchar(255) NOT NULL,
  `tab_icon` varchar(50) DEFAULT 'fas fa-link',
  `sort_order` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Stand-in structure for view `view_invoice_returns`
-- (See below for the actual view)
--
CREATE TABLE `view_invoice_returns` (
`invoice_id` int(11)
,`invoice_number` varchar(50)
,`invoice_date` date
,`supplier_name` varchar(100)
,`supplier_id` int(11)
,`total_items` bigint(21)
,`total_received` decimal(32,0)
,`total_defects` decimal(32,0)
,`total_returns` decimal(32,0)
,`failed_items` bigint(21)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `view_qc_returns_summary`
-- (See below for the actual view)
--
CREATE TABLE `view_qc_returns_summary` (
`record_id` int(11)
,`record_date` date
,`supplier_id` int(11)
,`supplier_name` varchar(100)
,`supplier_system_id` varchar(50)
,`invoice_number` varchar(50)
,`reference_number` varchar(50)
,`doc_number` varchar(50)
,`mode_name` varchar(50)
,`total_items` bigint(21)
,`total_quantity` decimal(32,0)
,`total_value` decimal(37,2)
,`total_images` bigint(21)
,`is_informed` tinyint(1)
,`is_store_received` tinyint(1)
,`is_gate_cleared` tinyint(1)
,`is_handover_complete` tinyint(1)
,`current_status` varchar(14)
,`added_by_user` varchar(50)
,`added_time` timestamp
);

-- --------------------------------------------------------

--
-- Structure for view `view_invoice_returns`
--
DROP TABLE IF EXISTS `view_invoice_returns`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `view_invoice_returns`  AS SELECT `si`.`invoice_id` AS `invoice_id`, `si`.`invoice_number` AS `invoice_number`, `si`.`invoice_date` AS `invoice_date`, `s`.`supplier_name` AS `supplier_name`, `s`.`supplier_id` AS `supplier_id`, count(`ii`.`invoice_item_id`) AS `total_items`, sum(`ii`.`received_qty`) AS `total_received`, sum(`ii`.`defect_qty`) AS `total_defects`, sum(`ii`.`return_qty`) AS `total_returns`, count(distinct case when `ii`.`status` = 'FAIL' then `ii`.`invoice_item_id` end) AS `failed_items` FROM ((`supplier_invoices` `si` left join `suppliers` `s` on(`si`.`supplier_id` = `s`.`supplier_id`)) left join `invoice_items` `ii` on(`si`.`invoice_id` = `ii`.`invoice_id`)) GROUP BY `si`.`invoice_id` ;

-- --------------------------------------------------------

--
-- Structure for view `view_qc_returns_summary`
--
DROP TABLE IF EXISTS `view_qc_returns_summary`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `view_qc_returns_summary`  AS SELECT `dm`.`record_id` AS `record_id`, `dm`.`record_date` AS `record_date`, `dm`.`supplier_id` AS `supplier_id`, `s`.`supplier_name` AS `supplier_name`, `s`.`system_id` AS `supplier_system_id`, `dm`.`invoice_number` AS `invoice_number`, `dm`.`reference_number` AS `reference_number`, `dm`.`doc_number` AS `doc_number`, `m`.`mode_name` AS `mode_name`, count(distinct `di`.`item_id`) AS `total_items`, sum(`di`.`quantity`) AS `total_quantity`, sum(`di`.`total_cost`) AS `total_value`, count(distinct `img`.`image_id`) AS `total_images`, `dm`.`is_informed` AS `is_informed`, `dm`.`is_store_received` AS `is_store_received`, `dm`.`is_gate_cleared` AS `is_gate_cleared`, `dm`.`is_handover_complete` AS `is_handover_complete`, CASE WHEN `dm`.`is_handover_complete` = 1 THEN 'Completed' WHEN `dm`.`is_gate_cleared` = 1 THEN 'Gate Cleared' WHEN `dm`.`is_store_received` = 1 THEN 'Store Received' WHEN `dm`.`is_informed` = 1 THEN 'Informed' ELSE 'New' END AS `current_status`, `dm`.`added_by_user` AS `added_by_user`, `dm`.`added_time` AS `added_time` FROM ((((`qc_damage_main` `dm` left join `suppliers` `s` on(`dm`.`supplier_id` = `s`.`supplier_id`)) left join `qc_modes` `m` on(`dm`.`mode_id` = `m`.`mode_id`)) left join `qc_damage_items` `di` on(`dm`.`record_id` = `di`.`record_id`)) left join `qc_item_images` `img` on(`dm`.`record_id` = `img`.`record_id`)) GROUP BY `dm`.`record_id` ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `branches`
--
ALTER TABLE `branches`
  ADD PRIMARY KEY (`branch_id`),
  ADD UNIQUE KEY `branch_code` (`branch_code`);

--
-- Indexes for table `floors`
--
ALTER TABLE `floors`
  ADD PRIMARY KEY (`floor_id`);

--
-- Indexes for table `invoice_items`
--
ALTER TABLE `invoice_items`
  ADD PRIMARY KEY (`invoice_item_id`),
  ADD KEY `fk_ii_item` (`item_id`),
  ADD KEY `idx_invoice_items_invoice` (`invoice_id`);

--
-- Indexes for table `items`
--
ALTER TABLE `items`
  ADD PRIMARY KEY (`item_id`),
  ADD UNIQUE KEY `unique_item_code` (`item_code`),
  ADD KEY `fk_items_supplier` (`supplier_id`),
  ADD KEY `idx_items_code` (`item_code`);

--
-- Indexes for table `item_return_reasons`
--
ALTER TABLE `item_return_reasons`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_item_reason` (`invoice_item_id`,`reason_id`),
  ADD KEY `fk_irr_reason` (`reason_id`);

--
-- Indexes for table `master_tabs`
--
ALTER TABLE `master_tabs`
  ADD PRIMARY KEY (`tab_id`),
  ADD UNIQUE KEY `tab_name` (`tab_name`);

--
-- Indexes for table `qc_audit_log`
--
ALTER TABLE `qc_audit_log`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `idx_record_id` (`record_id`),
  ADD KEY `idx_action` (`action`);

--
-- Indexes for table `qc_damage_items`
--
ALTER TABLE `qc_damage_items`
  ADD PRIMARY KEY (`item_id`),
  ADD KEY `idx_record_id` (`record_id`),
  ADD KEY `idx_qc_damage_items_record` (`record_id`);

--
-- Indexes for table `qc_damage_main`
--
ALTER TABLE `qc_damage_main`
  ADD PRIMARY KEY (`record_id`),
  ADD KEY `idx_supplier_id` (`supplier_id`),
  ADD KEY `idx_invoice_number` (`invoice_number`),
  ADD KEY `idx_mode_id` (`mode_id`),
  ADD KEY `idx_reason_id` (`reason_id`),
  ADD KEY `idx_record_date` (`record_date`),
  ADD KEY `idx_is_informed` (`is_informed`),
  ADD KEY `idx_is_store_received` (`is_store_received`),
  ADD KEY `idx_is_gate_cleared` (`is_gate_cleared`),
  ADD KEY `idx_is_handover_complete` (`is_handover_complete`),
  ADD KEY `idx_invoice_supplier` (`invoice_number`,`supplier_id`),
  ADD KEY `idx_flag_portal_main` (`record_date`,`is_handover_complete`,`supplier_id`,`record_id`),
  ADD KEY `idx_search_reference` (`reference_number`,`invoice_number`),
  ADD KEY `idx_pending_flag_count` (`is_handover_complete`),
  ADD KEY `idx_record_id_desc` (`record_id`),
  ADD KEY `idx_covering_flag_portal` (`record_date`,`supplier_id`,`is_handover_complete`,`record_id`,`reference_number`,`invoice_number`,`doc_number`,`mode_id`,`is_informed`,`is_store_received`,`is_gate_cleared`),
  ADD KEY `idx_search_all` (`reference_number`,`invoice_number`,`doc_number`),
  ADD KEY `idx_stats_date_status` (`record_date`,`is_handover_complete`),
  ADD KEY `idx_where_pattern` (`record_date`,`is_handover_complete`,`supplier_id`),
  ADD KEY `idx_join_supplier` (`supplier_id`,`record_date`,`is_handover_complete`),
  ADD KEY `idx_print_count` (`print_count`),
  ADD KEY `idx_parcel_count` (`parcel_count`),
  ADD KEY `idx_reference_number` (`reference_number`),
  ADD KEY `idx_qc_damage_main_reference` (`reference_number`),
  ADD KEY `idx_qc_damage_main_supplier` (`supplier_id`),
  ADD KEY `idx_qc_damage_main_date` (`record_date`);

--
-- Indexes for table `qc_item_images`
--
ALTER TABLE `qc_item_images`
  ADD PRIMARY KEY (`image_id`),
  ADD KEY `idx_record_id` (`record_id`);

--
-- Indexes for table `qc_modes`
--
ALTER TABLE `qc_modes`
  ADD PRIMARY KEY (`mode_id`);

--
-- Indexes for table `qc_return_reasons`
--
ALTER TABLE `qc_return_reasons`
  ADD PRIMARY KEY (`reason_id`);

--
-- Indexes for table `qc_tasks`
--
ALTER TABLE `qc_tasks`
  ADD PRIMARY KEY (`task_id`),
  ADD KEY `idx_record_id` (`record_id`),
  ADD KEY `idx_assigned_to` (`assigned_to`);

--
-- Indexes for table `return_reasons`
--
ALTER TABLE `return_reasons`
  ADD PRIMARY KEY (`reason_id`);

--
-- Indexes for table `suppliers`
--
ALTER TABLE `suppliers`
  ADD PRIMARY KEY (`supplier_id`),
  ADD KEY `idx_supplier_name` (`supplier_name`),
  ADD KEY `idx_contact_person` (`contact_person`),
  ADD KEY `idx_contact_number` (`contact_number`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_email` (`email`);

--
-- Indexes for table `supplier_invoices`
--
ALTER TABLE `supplier_invoices`
  ADD PRIMARY KEY (`invoice_id`),
  ADD UNIQUE KEY `unique_invoice_num` (`invoice_number`),
  ADD KEY `fk_si_branch` (`branch_id`),
  ADD KEY `fk_si_floor` (`floor_id`),
  ADD KEY `idx_invoice_number` (`invoice_number`),
  ADD KEY `idx_supplier_id` (`supplier_id`),
  ADD KEY `idx_invoice_date` (`invoice_date`),
  ADD KEY `idx_supplier_invoices_supplier` (`supplier_id`),
  ADD KEY `idx_supplier_invoices_number` (`invoice_number`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indexes for table `user_tabs`
--
ALTER TABLE `user_tabs`
  ADD PRIMARY KEY (`assignment_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `tab_id` (`tab_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `branches`
--
ALTER TABLE `branches`
  MODIFY `branch_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `floors`
--
ALTER TABLE `floors`
  MODIFY `floor_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `invoice_items`
--
ALTER TABLE `invoice_items`
  MODIFY `invoice_item_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `items`
--
ALTER TABLE `items`
  MODIFY `item_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `item_return_reasons`
--
ALTER TABLE `item_return_reasons`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `master_tabs`
--
ALTER TABLE `master_tabs`
  MODIFY `tab_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `qc_audit_log`
--
ALTER TABLE `qc_audit_log`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `qc_damage_items`
--
ALTER TABLE `qc_damage_items`
  MODIFY `item_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `qc_damage_main`
--
ALTER TABLE `qc_damage_main`
  MODIFY `record_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `qc_item_images`
--
ALTER TABLE `qc_item_images`
  MODIFY `image_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `qc_modes`
--
ALTER TABLE `qc_modes`
  MODIFY `mode_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `qc_return_reasons`
--
ALTER TABLE `qc_return_reasons`
  MODIFY `reason_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `qc_tasks`
--
ALTER TABLE `qc_tasks`
  MODIFY `task_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `return_reasons`
--
ALTER TABLE `return_reasons`
  MODIFY `reason_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `suppliers`
--
ALTER TABLE `suppliers`
  MODIFY `supplier_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2654;

--
-- AUTO_INCREMENT for table `supplier_invoices`
--
ALTER TABLE `supplier_invoices`
  MODIFY `invoice_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_tabs`
--
ALTER TABLE `user_tabs`
  MODIFY `assignment_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `invoice_items`
--
ALTER TABLE `invoice_items`
  ADD CONSTRAINT `fk_ii_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `supplier_invoices` (`invoice_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_ii_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`item_id`) ON UPDATE CASCADE;

--
-- Constraints for table `items`
--
ALTER TABLE `items`
  ADD CONSTRAINT `fk_items_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`supplier_id`) ON UPDATE CASCADE;

--
-- Constraints for table `item_return_reasons`
--
ALTER TABLE `item_return_reasons`
  ADD CONSTRAINT `fk_irr_invoice_item` FOREIGN KEY (`invoice_item_id`) REFERENCES `invoice_items` (`invoice_item_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_irr_reason` FOREIGN KEY (`reason_id`) REFERENCES `return_reasons` (`reason_id`) ON UPDATE CASCADE;

--
-- Constraints for table `qc_damage_items`
--
ALTER TABLE `qc_damage_items`
  ADD CONSTRAINT `fk_qc_damage_items_record` FOREIGN KEY (`record_id`) REFERENCES `qc_damage_main` (`record_id`) ON DELETE CASCADE;

--
-- Constraints for table `qc_damage_main`
--
ALTER TABLE `qc_damage_main`
  ADD CONSTRAINT `fk_qc_damage_main_mode` FOREIGN KEY (`mode_id`) REFERENCES `qc_modes` (`mode_id`) ON DELETE SET NULL;

--
-- Constraints for table `qc_item_images`
--
ALTER TABLE `qc_item_images`
  ADD CONSTRAINT `fk_qc_item_images_record` FOREIGN KEY (`record_id`) REFERENCES `qc_damage_main` (`record_id`) ON DELETE CASCADE;

--
-- Constraints for table `qc_tasks`
--
ALTER TABLE `qc_tasks`
  ADD CONSTRAINT `fk_qc_tasks_record` FOREIGN KEY (`record_id`) REFERENCES `qc_damage_main` (`record_id`) ON DELETE CASCADE;

--
-- Constraints for table `supplier_invoices`
--
ALTER TABLE `supplier_invoices`
  ADD CONSTRAINT `fk_si_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`branch_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_si_floor` FOREIGN KEY (`floor_id`) REFERENCES `floors` (`floor_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_si_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`supplier_id`) ON UPDATE CASCADE;

--
-- Constraints for table `user_tabs`
--
ALTER TABLE `user_tabs`
  ADD CONSTRAINT `user_tabs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `user_tabs_ibfk_2` FOREIGN KEY (`tab_id`) REFERENCES `master_tabs` (`tab_id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
