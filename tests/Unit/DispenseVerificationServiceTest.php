<?php

namespace Tests\Unit;

use App\Models\Nurse;
use App\Models\Patient;
use App\Models\PatientMealCassette;
use App\Services\DispenseVerificationService;
use Tests\TestCase;

// Unit tests for the QR verify-dispense decision logic - the heart of the AR
// dispensing flow. No database involved: instances below are constructed in
// memory and never saved. Cassette-level now (a scan resolves to a meal that
// may hold several drugs, not one drug directly) - per-drug "already
// dispensed today" checks live in the actual dispense step instead, since a
// cassette can legitimately be re-scanned after some of its drugs are
// already given today.
class DispenseVerificationServiceTest extends TestCase
{
    private DispenseVerificationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DispenseVerificationService();
    }

    private function makePatient(int $id): Patient
    {
        $patient = new Patient(['full_name' => 'สมชาย ใจงาม', 'qr_code_patient' => 'PATIENT-0001']);
        $patient->id = $id;
        return $patient;
    }

    private function makeCassette(int $id, int $patientId, string $meal = 'breakfast'): PatientMealCassette
    {
        $cassette = new PatientMealCassette(['meal' => $meal, 'qr_code' => "CASSETTE-{$patientId}-{$meal}"]);
        $cassette->id = $id;
        $cassette->patient_id = $patientId;
        return $cassette;
    }

    /** @test */
    public function it_rejects_when_nurse_qr_does_not_match_any_nurse()
    {
        $result = $this->service->evaluateCassette(null, $this->makePatient(1), $this->makeCassette(1, 1));

        $this->assertSame('incorrect', $result['result']);
        $this->assertSame('ไม่พบข้อมูลพยาบาลจาก QR นี้', $result['message']);
    }

    /** @test */
    public function it_rejects_when_patient_qr_does_not_match_any_patient()
    {
        $nurse = new Nurse(['full_name' => 'สุดา ใจดี', 'qr_code_nurse' => 'NURSE-001']);

        $result = $this->service->evaluateCassette($nurse, null, $this->makeCassette(1, 1));

        $this->assertSame('incorrect', $result['result']);
        $this->assertSame('ไม่พบข้อมูลผู้ป่วยจาก QR นี้', $result['message']);
    }

    /** @test */
    public function it_rejects_when_cassette_qr_does_not_match_any_cassette()
    {
        $nurse = new Nurse(['full_name' => 'สุดา ใจดี', 'qr_code_nurse' => 'NURSE-001']);
        $patient = $this->makePatient(1);

        $result = $this->service->evaluateCassette($nurse, $patient, null);

        $this->assertSame('incorrect', $result['result']);
        $this->assertSame('QR code ไม่ถูกต้อง', $result['message']);
    }

    /** @test */
    public function it_rejects_when_the_cassette_belongs_to_a_different_patient()
    {
        $nurse = new Nurse(['full_name' => 'สุดา ใจดี', 'qr_code_nurse' => 'NURSE-001']);
        $patient = $this->makePatient(1);
        $cassette = $this->makeCassette(1, 999); // belongs to patient 999, not 1

        $result = $this->service->evaluateCassette($nurse, $patient, $cassette);

        $this->assertSame('incorrect', $result['result']);
        $this->assertSame('ตลับยานี้ไม่ใช่ของผู้ป่วยรายนี้', $result['message']);
    }

    /** @test */
    public function it_approves_a_cassette_that_belongs_to_the_scanned_patient()
    {
        $nurse = new Nurse(['full_name' => 'สุดา ใจดี', 'qr_code_nurse' => 'NURSE-001']);
        $patient = $this->makePatient(1);
        $cassette = $this->makeCassette(1, 1, 'lunch');

        $result = $this->service->evaluateCassette($nurse, $patient, $cassette);

        $this->assertSame('correct', $result['result']);
    }

    /** @test */
    public function it_checks_nurse_before_patient_and_patient_before_cassette()
    {
        // All three missing at once - the nurse message must win (checked first).
        $result = $this->service->evaluateCassette(null, null, null);
        $this->assertSame('ไม่พบข้อมูลพยาบาลจาก QR นี้', $result['message']);

        // Nurse found, patient and cassette missing - patient message wins.
        $nurse = new Nurse(['full_name' => 'สุดา ใจดี', 'qr_code_nurse' => 'NURSE-001']);
        $result = $this->service->evaluateCassette($nurse, null, null);
        $this->assertSame('ไม่พบข้อมูลผู้ป่วยจาก QR นี้', $result['message']);
    }
}
