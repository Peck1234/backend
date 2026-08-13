<?php

namespace Database\Seeders;

use App\Models\Medication;
use App\Models\Nurse;
use App\Models\Patient;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {
        Nurse::create([
            'username' => 'suda.rn',
            'password' => Hash::make('nurse123'),
            'full_name' => 'สุดา ใจดี',
            'qr_code_nurse' => 'NURSE-SUDA-001',
        ]);

        $patientA = Patient::create([
            'full_name' => 'สมชาย ใจงาม',
            'ward' => 'อายุรกรรมชาย',
            'bed_no' => '12',
            'qr_code_patient' => 'PATIENT-0001',
        ]);

        $patientB = Patient::create([
            'full_name' => 'สมหญิง มีสุข',
            'ward' => 'ศัลยกรรมหญิง',
            'bed_no' => '5',
            'qr_code_patient' => 'PATIENT-0002',
        ]);

        Medication::create([
            'patient_id' => $patientA->id,
            'drug_name' => 'Paracetamol',
            'standard_dose' => '500mg',
            'purpose' => 'ลดไข้',
            'dose' => '1 เม็ด',
            'instruction' => 'รับประทานหลังอาหารทุก 6 ชั่วโมง',
            'time_slot' => '08:00,12:00,18:00,21:00',
            'qr_code_cassette' => 'CASSETTE-0001',
        ]);

        Medication::create([
            'patient_id' => $patientA->id,
            'drug_name' => 'Amoxicillin',
            'standard_dose' => '250mg',
            'purpose' => 'ฆ่าเชื้อแบคทีเรีย',
            'dose' => '1 แคปซูล',
            'instruction' => 'รับประทานก่อนอาหารทุก 8 ชั่วโมง',
            'time_slot' => '08:00,12:00,18:00',
            'qr_code_cassette' => 'CASSETTE-0002',
        ]);

        Medication::create([
            'patient_id' => $patientB->id,
            'drug_name' => 'Ibuprofen',
            'standard_dose' => '400mg',
            'purpose' => 'ลดปวด',
            'dose' => '1 เม็ด',
            'instruction' => 'รับประทานหลังอาหารทันที',
            'time_slot' => null,
            'qr_code_cassette' => 'CASSETTE-0003',
        ]);

        $patientC = Patient::create([
            'full_name' => 'Mr. Shang Buk',
            'ward' => 'อายุรกรรมหญิง',
            'bed_no' => '2',
            'qr_code_patient' => 'PATIENT-0003',
        ]);

        $patientCMedications = [
            ['drug_name' => 'Glipizide', 'standard_dose' => '5 mg', 'purpose' => 'รักษาเบาหวาน', 'dose' => 'ครึ่งเม็ด', 'instruction' => 'วันละ 2 ครั้ง ก่อนอาหาร เช้า-เย็น', 'time_slot' => '08:00,18:00'],
            ['drug_name' => 'Diacerein', 'standard_dose' => '50 mg', 'purpose' => 'รักษาข้อเสื่อม', 'dose' => '1 เม็ด', 'instruction' => 'วันละ 1 ครั้ง ก่อนอาหารเช้า', 'time_slot' => '08:00'],
            ['drug_name' => 'Glucosamine', 'standard_dose' => '1500 mg', 'purpose' => 'บำรุงข้อ', 'dose' => 'ชงในน้ำครึ่งแก้ว', 'instruction' => 'วันละ 1 ครั้ง ก่อนอาหารเช้า', 'time_slot' => '08:00'],
            ['drug_name' => 'Mirtazapine', 'standard_dose' => '30 mg', 'purpose' => 'รักษาซึมเศร้า', 'dose' => '1 เม็ด', 'instruction' => 'วันละ 1 ครั้ง ก่อนนอน', 'time_slot' => '21:00'],
            ['drug_name' => 'Day-vi-go', 'standard_dose' => '5 mg', 'purpose' => 'ยานอนหลับ', 'dose' => '1 เม็ด', 'instruction' => 'วันละ 1 ครั้ง ก่อนนอน', 'time_slot' => '21:00'],
            ['drug_name' => 'Clonazepam', 'standard_dose' => '2 mg', 'purpose' => 'ยาคลายกังวล', 'dose' => '1 เม็ด', 'instruction' => 'วันละ 1 ครั้ง ก่อนนอน', 'time_slot' => '21:00'],
            ['drug_name' => 'Senokot', 'standard_dose' => '7.5 mg', 'purpose' => 'ยาระบาย', 'dose' => '2 เม็ด', 'instruction' => 'วันละ 1 ครั้ง ก่อนนอน', 'time_slot' => '21:00'],
            ['drug_name' => 'Arcoxia', 'standard_dose' => '90 mg', 'purpose' => 'ยาแก้ปวด', 'dose' => '1 เม็ด', 'instruction' => 'วันละ 1 ครั้ง หลังอาหารเช้า', 'time_slot' => '08:00'],
            ['drug_name' => 'Vit B12', 'standard_dose' => null, 'purpose' => null, 'dose' => '1 เม็ด', 'instruction' => 'วันละ 1 ครั้ง หลังอาหารเช้า', 'time_slot' => '08:00'],
            ['drug_name' => 'Myonol', 'standard_dose' => '50 mg', 'purpose' => 'คลายกล้ามเนื้อ', 'dose' => '1 เม็ด', 'instruction' => 'วันละ 3 ครั้ง หลังอาหารเช้า-กลางวัน-เย็น', 'time_slot' => '08:00,12:00,18:00'],
            ['drug_name' => 'Caltab', 'standard_dose' => '1500 mg', 'purpose' => null, 'dose' => '1 เม็ด', 'instruction' => 'วันละ 3 ครั้ง หลังอาหารเช้า-กลางวัน-เย็น', 'time_slot' => '08:00,12:00,18:00'],
            ['drug_name' => 'Lyrica', 'standard_dose' => '75 mg', 'purpose' => 'แก้ปวดปลายประสาท', 'dose' => '1 เม็ด', 'instruction' => 'วันละ 1 ครั้ง ก่อนนอน', 'time_slot' => '21:00'],
            ['drug_name' => 'Quetiapine', 'standard_dose' => '25 mg', 'purpose' => 'ยาต้านโรคจิต', 'dose' => '1 เม็ด', 'instruction' => 'วันละ 1 ครั้ง ก่อนนอน', 'time_slot' => '21:00'],
        ];

        foreach ($patientCMedications as $index => $medication) {
            Medication::create(array_merge($medication, [
                'patient_id' => $patientC->id,
                'qr_code_cassette' => 'CASSETTE-' . str_pad(3 + $index + 1, 4, '0', STR_PAD_LEFT),
            ]));
        }

        $patientD = Patient::create([
            'full_name' => 'สมศักดิ์ แข็งแรง',
            'ward' => 'อายุรกรรมชาย',
            'bed_no' => 'B4',
            'qr_code_patient' => 'PATIENT-0004',
        ]);

        $patientDMedications = [
            ['drug_name' => 'Eltroxin', 'standard_dose' => '0.1 mg', 'purpose' => 'รักษาไทรอยด์', 'dose' => '1 เม็ด', 'instruction' => 'วันละ 1 ครั้ง ก่อนอาหารเช้า', 'time_slot' => '08:00'],
            ['drug_name' => 'Zinc', 'standard_dose' => '25 mg', 'purpose' => 'เสริมธาตุสังกะสี', 'dose' => '1 เม็ด', 'instruction' => 'วันละ 1 ครั้ง ก่อนอาหารเช้า', 'time_slot' => '08:00'],
            ['drug_name' => 'Folic', 'standard_dose' => '5 mg', 'purpose' => 'เสริมโฟลิก', 'dose' => '1 เม็ด', 'instruction' => 'วันละ 1 ครั้ง หลังอาหารเช้า', 'time_slot' => '08:00'],
            ['drug_name' => 'Vitamin D2', 'standard_dose' => null, 'purpose' => 'เสริมวิตามินดี2', 'dose' => '1 เม็ด', 'instruction' => 'สัปดาห์ละ 1 ครั้ง หลังอาหารเช้า วันอาทิตย์', 'time_slot' => '08:00'],
            ['drug_name' => 'Fe', 'standard_dose' => null, 'purpose' => 'เสริมธาตุเหล็ก', 'dose' => '1 เม็ด', 'instruction' => 'วันละ 3 ครั้ง หลังอาหารเช้า-กลางวัน-เย็น', 'time_slot' => '08:00,12:00,18:00'],
            ['drug_name' => 'Vit C', 'standard_dose' => null, 'purpose' => null, 'dose' => '1 เม็ด', 'instruction' => 'วันละ 3 ครั้ง หลังอาหารเช้า-กลางวัน-เย็น', 'time_slot' => '08:00,12:00,18:00'],
            ['drug_name' => 'Atorvastatin', 'standard_dose' => '40 mg', 'purpose' => 'ลดไขมันในเลือด', 'dose' => '1 เม็ด', 'instruction' => 'วันละ 1 ครั้ง ก่อนนอน', 'time_slot' => '21:00'],
            ['drug_name' => 'Caltab', 'standard_dose' => '1500 mg', 'purpose' => null, 'dose' => '1 เม็ด', 'instruction' => 'วันละ 1 ครั้ง ก่อนอาหารเช้า *ไม่พร้อมกับ Fe', 'time_slot' => '08:00'],
            ['drug_name' => 'Cravit', 'standard_dose' => '500 mg', 'purpose' => 'ยาปฏิชีวนะ', 'dose' => '1.5 เม็ด', 'instruction' => 'วันละ 1 ครั้ง หลังอาหารเช้า 1-10 ก.ค. 69', 'time_slot' => '08:00'],
            ['drug_name' => 'NaCl', 'standard_dose' => '300 mg', 'purpose' => null, 'dose' => '1 เม็ด', 'instruction' => 'วันละ 3 ครั้ง หลังอาหารเช้า-กลางวัน-เย็น', 'time_slot' => '08:00,12:00,18:00'],
        ];

        foreach ($patientDMedications as $index => $medication) {
            Medication::create(array_merge($medication, [
                'patient_id' => $patientD->id,
                'qr_code_cassette' => 'CASSETTE-' . str_pad(16 + $index + 1, 4, '0', STR_PAD_LEFT),
            ]));
        }
    }
}
