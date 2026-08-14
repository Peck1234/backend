<?php

namespace Tests\Unit;

use App\Models\Medication;
use App\Models\Nurse;
use App\Models\Patient;
use App\Services\DispenseVerificationService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

// Unit tests for the QR verify-dispense decision logic - the heart of the AR
// dispensing flow. No database involved: Nurse/Patient/Medication instances
// below are constructed in memory and never saved.
class DispenseVerificationServiceTest extends TestCase
{
    private DispenseVerificationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DispenseVerificationService();
        Carbon::setTestNow(Carbon::parse('2026-08-14 10:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makePatient(int $id): Patient
    {
        $patient = new Patient(['full_name' => 'สมชาย ใจงาม', 'qr_code_patient' => 'PATIENT-0001']);
        $patient->id = $id;
        return $patient;
    }

    private function makeMedication(int $id, int $patientId, string $drugName = 'Paracetamol', ?Carbon $dispensedAt = null): Medication
    {
        $medication = new Medication([
            'drug_name' => $drugName,
            'qr_code_cassette' => 'CASSETTE-0001',
            'dispensed_at' => $dispensedAt,
        ]);
        $medication->id = $id;
        $medication->patient_id = $patientId;
        return $medication;
    }

    /** @test */
    public function it_rejects_when_nurse_qr_does_not_match_any_nurse()
    {
        $result = $this->service->evaluate(null, $this->makePatient(1), $this->makeMedication(1, 1));

        $this->assertSame('incorrect', $result['result']);
        $this->assertNull($result['drug_name']);
        $this->assertSame('ไม่พบข้อมูลพยาบาลจาก QR นี้', $result['message']);
        $this->assertFalse($result['should_dispense']);
    }

    /** @test */
    public function it_rejects_when_patient_qr_does_not_match_any_patient()
    {
        $nurse = new Nurse(['full_name' => 'สุดา ใจดี', 'qr_code_nurse' => 'NURSE-001']);

        $result = $this->service->evaluate($nurse, null, $this->makeMedication(1, 1));

        $this->assertSame('incorrect', $result['result']);
        $this->assertNull($result['drug_name']);
        $this->assertSame('ไม่พบข้อมูลผู้ป่วยจาก QR นี้', $result['message']);
        $this->assertFalse($result['should_dispense']);
    }

    /** @test */
    public function it_rejects_when_cassette_qr_does_not_match_any_medication()
    {
        $nurse = new Nurse(['full_name' => 'สุดา ใจดี', 'qr_code_nurse' => 'NURSE-001']);
        $patient = $this->makePatient(1);

        $result = $this->service->evaluate($nurse, $patient, null);

        $this->assertSame('incorrect', $result['result']);
        $this->assertNull($result['drug_name']);
        $this->assertSame('QR code ไม่ถูกต้อง', $result['message']);
        $this->assertFalse($result['should_dispense']);
    }

    /** @test */
    public function it_rejects_when_the_cassette_belongs_to_a_different_patient()
    {
        $nurse = new Nurse(['full_name' => 'สุดา ใจดี', 'qr_code_nurse' => 'NURSE-001']);
        $patient = $this->makePatient(1);
        $medication = $this->makeMedication(1, 999, 'Amoxicillin'); // belongs to patient 999, not 1

        $result = $this->service->evaluate($nurse, $patient, $medication);

        $this->assertSame('incorrect', $result['result']);
        $this->assertSame('Amoxicillin', $result['drug_name']); // drug name still surfaces, so the nurse knows what they scanned
        $this->assertSame('ตลับยานี้ไม่ใช่ของผู้ป่วยรายนี้', $result['message']);
        $this->assertFalse($result['should_dispense']);
    }

    /** @test */
    public function it_rejects_a_medication_already_dispensed_today()
    {
        $nurse = new Nurse(['full_name' => 'สุดา ใจดี', 'qr_code_nurse' => 'NURSE-001']);
        $patient = $this->makePatient(1);
        $medication = $this->makeMedication(1, 1, 'Paracetamol', Carbon::parse('2026-08-14 08:00:00'));

        $result = $this->service->evaluate($nurse, $patient, $medication);

        $this->assertSame('incorrect', $result['result']);
        $this->assertSame('Paracetamol', $result['drug_name']);
        $this->assertSame('ยานี้ถูกจ่ายไปแล้ววันนี้', $result['message']);
        $this->assertFalse($result['should_dispense']);
    }

    /** @test */
    public function it_allows_a_medication_dispensed_on_a_previous_day_to_be_dispensed_again_today()
    {
        $nurse = new Nurse(['full_name' => 'สุดา ใจดี', 'qr_code_nurse' => 'NURSE-001']);
        $patient = $this->makePatient(1);
        $medication = $this->makeMedication(1, 1, 'Paracetamol', Carbon::parse('2026-08-13 08:00:00'));

        $result = $this->service->evaluate($nurse, $patient, $medication);

        $this->assertSame('correct', $result['result']);
        $this->assertTrue($result['should_dispense']);
    }

    /** @test */
    public function it_approves_a_correct_scan_with_nothing_dispensed_yet()
    {
        $nurse = new Nurse(['full_name' => 'สุดา ใจดี', 'qr_code_nurse' => 'NURSE-001']);
        $patient = $this->makePatient(1);
        $medication = $this->makeMedication(1, 1, 'Paracetamol', null);

        $result = $this->service->evaluate($nurse, $patient, $medication);

        $this->assertSame('correct', $result['result']);
        $this->assertSame('Paracetamol', $result['drug_name']);
        $this->assertSame('จ่ายยาสำเร็จ', $result['message']);
        $this->assertTrue($result['should_dispense']);
    }

    /** @test */
    public function it_checks_nurse_before_patient_and_patient_before_medication()
    {
        // All three missing at once - the nurse message must win (checked first).
        $result = $this->service->evaluate(null, null, null);
        $this->assertSame('ไม่พบข้อมูลพยาบาลจาก QR นี้', $result['message']);

        // Nurse found, patient and medication missing - patient message wins.
        $nurse = new Nurse(['full_name' => 'สุดา ใจดี', 'qr_code_nurse' => 'NURSE-001']);
        $result = $this->service->evaluate($nurse, null, null);
        $this->assertSame('ไม่พบข้อมูลผู้ป่วยจาก QR นี้', $result['message']);
    }
}
