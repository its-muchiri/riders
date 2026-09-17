<?php

namespace Rider\Controllers;

use Rider\Config\Database;
use Rider\Core\Request;
use Rider\Core\Response;

/**
 * Rider onboarding — creates KYC document submissions AND the
 * rider_vehicle_documents record (see database/schema.sql), since this
 * platform's trust bar for going online is deliberately raised above the
 * generic Tier 1 default: driving license + insurance proof are required
 * even for low-value trips, given road-safety stakes (see
 * planning/02-rider-co-ke/user-flows.md step 2 and open-questions.md's
 * flagged assumption about this deliberate override).
 */
final class OnboardingController
{
    public function submit(Request $request): void
    {
        $db = Database::connection();
        $riderId = $request->user['id'] ?? null;

        $requiredDocs = ['national_id', 'driving_license', 'insurance_certificate'];
        $documents = $request->input('documents', []);
        $submittedTypes = array_column($documents, 'document_type');

        foreach ($requiredDocs as $required) {
            if (!in_array($required, $submittedTypes, true)) {
                Response::error("Missing required document: {$required}", 422, ['required' => $requiredDocs]);
                return;
            }
        }

        $db->beginTransaction();
        try {
            $stmt = $db->prepare(
                'INSERT INTO kyc_documents (user_id, document_type, file_reference, verification_status)
                 VALUES (:user_id, :document_type, :file_reference, "pending")'
            );
            $documentIds = [];
            foreach ($documents as $document) {
                $stmt->execute([
                    'user_id' => $riderId,
                    'document_type' => $document['document_type'],
                    'file_reference' => $document['file_reference'],
                ]);
                $documentIds[$document['document_type']] = (int) $db->lastInsertId();
            }

            $stmt = $db->prepare(
                'INSERT INTO rider_vehicle_documents
                    (rider_id, vehicle_type, plate_number, driving_license_kyc_document_id, insurance_kyc_document_id, logbook_kyc_document_id, verification_status, created_at)
                 VALUES (:rider_id, :vehicle_type, :plate_number, :license_doc_id, :insurance_doc_id, :logbook_doc_id, "pending", NOW())'
            );
            $stmt->execute([
                'rider_id' => $riderId,
                'vehicle_type' => $request->input('vehicle_type'),
                'plate_number' => $request->input('plate_number'),
                'license_doc_id' => $documentIds['driving_license'],
                'insurance_doc_id' => $documentIds['insurance_certificate'],
                'logbook_doc_id' => $documentIds['vehicle_logbook'] ?? null, // optional, per rider_vehicle_documents.logbook_kyc_document_id being nullable
            ]);

            $stmt = $db->prepare('UPDATE users SET status = "pending_verification" WHERE id = :id');
            $stmt->execute(['id' => $riderId]);

            $db->commit();
            Response::json(['status' => 'pending_verification'], 201);
        } catch (\Throwable $e) {
            $db->rollBack();
            Response::error('Onboarding submission failed', 500, ['reason' => $e->getMessage()]);
        }
    }
}
