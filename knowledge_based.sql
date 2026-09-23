-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Sep 23, 2026 at 03:05 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `knowledge_based`
--

-- --------------------------------------------------------

--
-- Table structure for table `documents`
--

CREATE TABLE `documents` (
  `id` int(11) NOT NULL,
  `document_name` varchar(255) DEFAULT NULL,
  `section` varchar(255) DEFAULT NULL,
  `chunk_text` text DEFAULT NULL,
  `keywords` text DEFAULT NULL,
  `category` varchar(100) DEFAULT NULL,
  `version` varchar(255) DEFAULT NULL,
  `status` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `documents`
--

INSERT INTO `documents` (`id`, `document_name`, `section`, `chunk_text`, `keywords`, `category`, `version`, `status`) VALUES
(1, 'TEMPLATE FIRST REPORT (FIRST EVALUATION).docx', 'General', 'FACULTY OF COMPUTING UNIVERSITI MALAYSIA PAHANG AL-SULTAN ABDULLAH\nSESSION: SEMESTER 1 2026/2027\nINDUSTRIAL TRAINING FIRST REPORT\n(CODE DRC2912)\ntm technology services. (Industrial Supervisor Name)', 'general, of, pahang, al, sultan, first, report, code, industrial, faculty, computing, universiti', 'report', '2026-09-21', 'extracted'),
(2, 'TEMPLATE FIRST REPORT (FIRST EVALUATION).docx', 'STUDENT NAMESTUDENT ID', 'INDUSTRIAL TRAINING FIRST REPORT', 'student, namestudent, first, report, industrial, training, report', 'report', '2026-09-21', 'extracted'),
(3, 'TEMPLATE FIRST REPORT (FIRST EVALUATION).docx', 'INTRODUCTION', 'Please include introduction for Job Description / Job Scope/ Project Planning at your department/company.\nMaximum pages for this report is 5 and minimum 3 pages (not including cover and appendix pages)', 'introduction, pages, job, please, description, scope, project, planning', 'report', '2026-09-21', 'extracted'),
(4, 'TEMPLATE FIRST REPORT (FIRST EVALUATION).docx', 'PROGRESS', 'Please include Project Progress (week 1 - week 5) here', 'progress, week, please, project, here', 'report', '2026-09-21', 'extracted'),
(5, 'TEMPLATE FIRST REPORT (FIRST EVALUATION).docx', 'CONCLUSION', 'Conclusion regarding your job/project', 'conclusion, regarding, your, job, project', 'report', '2026-09-21', 'extracted'),
(6, 'TEMPLATE FIRST REPORT (FIRST EVALUATION).docx', 'APPENDIX', 'Include Gantt Chart during Industrial Training (Planning week 1 – week 20/24)', 'appendix, week, gantt, chart, during, industrial, training, planning', 'report', '2026-09-21', 'extracted'),
(7, 'RC24346_ReportDuty2.docx', 'General', 'Borang A1\nUNIT LATIHAN INDUSTRI\nBORANG PENGESAHAN MELAPORKAN DIRI & PENDAFTARAN KURSUS LATIHAN INDUSTRI\nCatatan:\n(i)          Borang ini hendaklah dilengkapkan oleh pelatih dan disahkan oleh Penyelia Industri.\n(ii)          Faks dan poskan borang ini sebaik sahaja melaporkan diri ke: Unit Latihan Industri,\nUniversiti Malaysia Pahang Al-Sultan Abdullah, Lebuh Persiaran Tun Khalil Yaakob, 23600 Kuantan Pahang              Telefon: 09 4315023                         E-mel: li@umpsa.edu.my\n(iii)        Pelatih wajib mendaftar kursus LI secara on-line dalam tempoh yang ditetapkan.\n1. Nama Penuh1. Nama PenuhRC24346RC243462. No. Pelajar2. No. PelajarNo. K/PNo. K/PDRC2912DRC29123. Kod-kod Kursus LI3. Kod-kod Kursus LISemesterSemester4. Program Pengajian4. Program Pengajian5. Nama & alamat lokasi Latihan Industri5. Nama & alamat lokasi Latihan Industri6. Alamat tempat tinggal semasa Latihan Industri6. Alamat tempat tinggal semasa Latihan Industri7. Tarikh melaporkan diri7. Tarikh melaporkan diri8. Elaun diterima8. Elaun diterimasebulan.sebulan.\nSaya sahkan maklumat di atas adalah benar,\n17 AUGUST 2026\n(Tandatangan Pelatih)                                                                             (Tarikh)\nDisahkan benar oleh Penyelia Industri,\n(Tandangan & cop rasmi)\nTarikh:       17 AUGUST 2026\nNama:           MEGAT NUR SHIDIQ BIN BAHARUDIN  Jawatan :                           MANAGER Emel:__________ shidiq.bahar@tm.com.my__________ Tel. : __0193249965______ Faks : ____________________', 'general, a1, unit, borang, diri, kursus, li, li3, august, megat, nur, shidiq', 'report', '2026-09-21', 'extracted'),
(8, 'RC24346_ReportDuty2.docx', 'Table 1', 'AHMAD MUSAB AL KHAIR BIN FAUZY', 'table, ahmad, musab, al, khair, bin, fauzy, ahmad, musab, khair, bin, fauzy', 'report', '2026-09-21', 'extracted'),
(9, 'RC24346_ReportDuty2.docx', 'Table 2', '060824140669', 'table', 'report', '2026-09-21', 'extracted'),
(10, 'RC24346_ReportDuty2.docx', 'Table 3', 'Sem 1 Sesi  2026/2027', 'table, sem, sesi', 'report', '2026-09-21', 'extracted'),
(11, 'RC24346_ReportDuty2.docx', 'Table 4', 'DIPLOMA IN COMPUTER SCIENCE', 'table, in, diploma, computer, science', 'report', '2026-09-21', 'extracted'),
(12, 'RC24346_ReportDuty2.docx', 'Table 5', 'TM Technology Services Sdn. Bhd. / Level 23, TM Annexe 2, Jalan Pantai Jaya, 59200 Kuala LumpurTelefon :   0193249965                      Faks :', 'table, tm, technology, services, sdn, bhd, level, annexe, jalan', 'report', '2026-09-21', 'extracted'),
(13, 'RC24346_ReportDuty2.docx', 'Table 6', 'NO 10, JALAN TH 1, 43900, SEPANG, SELANGORTelefon :                                          (HP):01111740109', 'table, no, jalan, th, sepang, hp, jalan, sepang, selangortelefon', 'report', '2026-09-21', 'extracted'),
(14, 'RC24346_ReportDuty2.docx', 'Table 7', '17 AUGUST 2026', 'table, august, august', 'report', '2026-09-21', 'extracted'),
(15, 'RC24346_ReportDuty2.docx', 'Table 8', 'RM            800', 'table, rm', 'report', '2026-09-21', 'extracted');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `documents`
--
ALTER TABLE `documents`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `documents`
--
ALTER TABLE `documents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
