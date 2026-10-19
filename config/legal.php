<?php

/**
 * Public legal texts of the platform (accounts of the people who sign in) and the record of who accepted which version.
 * The texts are DRAFTS until CCG's legal review: nothing here is legal advice, and the company data below is read from the
 * environment, never invented. Whatever is empty shows as "[POR CONFIRMAR]" on the page.
 *
 * A document is accepted per VERSION. Publishing a new text means raising its `version` here: everyone who accepted the
 * previous one is asked again, and the old acceptances stay as evidence. Commercial communications are NOT part of this list:
 * they have their own optional authorization (App\Models\CommunicationConsent).
 */
return [

    // Off until CCG confirms the texts: while off, nobody is asked to accept anything and the pages are only informative.
    'acceptance_required' => (bool) env('LEGAL_ACCEPTANCE_REQUIRED', false),

    // The banner "borrador pendiente de revisión jurídica" disappears only when this is true (set it after the legal review).
    'reviewed' => (bool) env('LEGAL_DOCUMENTS_REVIEWED', false),

    // Date from which the current versions apply (YYYY-MM-DD); empty = still to be confirmed.
    'effective_date' => env('LEGAL_EFFECTIVE_DATE'),

    // `required`: needed to use the service, so it is part of the acceptance. The text of each lives in resources/legal/{key}.php.
    'documents' => [
        'terms' => ['slug' => 'terminos-y-condiciones', 'title' => 'Términos y condiciones de uso', 'version' => '0.1', 'required' => true],
        'privacy_notice' => ['slug' => 'aviso-de-privacidad', 'title' => 'Aviso de privacidad', 'version' => '0.1', 'required' => true],
        'data_policy' => ['slug' => 'politica-de-tratamiento-de-datos', 'title' => 'Política de tratamiento de datos personales', 'version' => '0.1', 'required' => false],
    ],

    // Who is responsible for the data of the platform's own accounts. Every value is confirmed by CCG; none is assumed.
    'responsible' => [
        'legal_name' => env('LEGAL_COMPANY_NAME'),
        'tax_id' => env('LEGAL_COMPANY_TAX_ID'),
        'address' => env('LEGAL_COMPANY_ADDRESS'),
        'contact_email' => env('LEGAL_CONTACT_EMAIL'),
        'privacy_contact' => env('LEGAL_PRIVACY_CONTACT'),
        'jurisdiction' => env('LEGAL_JURISDICTION'),
    ],
];
