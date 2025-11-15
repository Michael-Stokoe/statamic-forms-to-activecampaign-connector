<?php

declare(strict_types=1);

namespace Stokoe\FormsToActivecampaignConnector;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Stokoe\FormsToWherever\Contracts\ConnectorInterface;
use Statamic\Forms\Submission;

class ActivecampaignConnector implements ConnectorInterface
{
    public function handle(): string
    {
        return 'activecampaign';
    }

    public function name(): string
    {
        return 'ActiveCampaign';
    }

    public function fieldset(): array
    {
        return [
            [
                'handle' => 'api_url',
                'field' => [
                    'type' => 'text',
                    'display' => 'API URL',
                    'instructions' => 'Your ActiveCampaign API URL (e.g., https://youraccountname.api-us1.com)',
                    'validate' => 'required',
                ],
            ],
            [
                'handle' => 'api_key',
                'field' => [
                    'type' => 'text',
                    'display' => 'API Key',
                    'instructions' => 'Your ActiveCampaign API key',
                    'validate' => 'required',
                ],
            ],
            [
                'handle' => 'list_id',
                'field' => [
                    'type' => 'text',
                    'display' => 'List ID',
                    'instructions' => 'ActiveCampaign list ID to subscribe to',
                    'validate' => 'required',
                ],
            ],
            [
                'handle' => 'email_field',
                'field' => [
                    'type' => 'text',
                    'display' => 'Email Field',
                    'instructions' => 'Form field containing the email address',
                    'default' => 'email',
                ],
            ],
            [
                'handle' => 'first_name_field',
                'field' => [
                    'type' => 'text',
                    'display' => 'First Name Field',
                    'instructions' => 'Form field containing the first name',
                    'default' => 'first_name',
                ],
            ],
            [
                'handle' => 'last_name_field',
                'field' => [
                    'type' => 'text',
                    'display' => 'Last Name Field',
                    'instructions' => 'Form field containing the last name',
                    'default' => 'last_name',
                ],
            ],
            [
                'handle' => 'tags',
                'field' => [
                    'type' => 'text',
                    'display' => 'Tags',
                    'instructions' => 'Comma-separated list of tags to apply',
                ],
            ],
            [
                'handle' => 'field_mapping',
                'field' => [
                    'type' => 'grid',
                    'display' => 'Custom Field Mapping',
                    'instructions' => 'Map form fields to ActiveCampaign custom fields',
                    'fields' => [
                        [
                            'handle' => 'form_field',
                            'field' => [
                                'type' => 'text',
                                'display' => 'Form Field',
                                'width' => 50,
                            ],
                        ],
                        [
                            'handle' => 'activecampaign_field',
                            'field' => [
                                'type' => 'text',
                                'display' => 'ActiveCampaign Field ID',
                                'instructions' => 'Custom field ID from ActiveCampaign',
                                'width' => 50,
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    public function process(Submission $submission, array $config): void
    {
        $apiUrl = rtrim($config['api_url'] ?? '', '/');
        $apiKey = $config['api_key'] ?? null;
        $listId = $config['list_id'] ?? null;
        $emailField = $config['email_field'] ?? 'email';
        $firstNameField = $config['first_name_field'] ?? 'first_name';
        $lastNameField = $config['last_name_field'] ?? 'last_name';
        $tags = $config['tags'] ?? '';
        $fieldMapping = $config['field_mapping'] ?? [];

        if (!$apiUrl || !$apiKey || !$listId) {
            Log::warning('ActiveCampaign connector: Missing required configuration', [
                'form' => $submission->form()->handle(),
                'submission_id' => $submission->id(),
            ]);
            return;
        }

        $formData = $submission->data()->toArray();
        $email = $formData[$emailField] ?? null;

        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Log::warning('ActiveCampaign connector: Invalid or missing email', [
                'form' => $submission->form()->handle(),
                'submission_id' => $submission->id(),
                'email_field' => $emailField,
                'email' => $email,
            ]);
            return;
        }

        // Create or update contact
        $contactData = [
            'contact' => [
                'email' => $email,
            ]
        ];

        // Add first name
        if (isset($formData[$firstNameField])) {
            $contactData['contact']['firstName'] = $formData[$firstNameField];
        }

        // Add last name
        if (isset($formData[$lastNameField])) {
            $contactData['contact']['lastName'] = $formData[$lastNameField];
        }

        // Add custom fields
        $fieldValues = [];
        foreach ($fieldMapping as $mapping) {
            $formField = $mapping['form_field'] ?? '';
            $acField = $mapping['activecampaign_field'] ?? '';

            if ($formField && $acField && isset($formData[$formField])) {
                $fieldValues[] = [
                    'field' => $acField,
                    'value' => $formData[$formField],
                ];
            }
        }

        if (!empty($fieldValues)) {
            $contactData['contact']['fieldValues'] = $fieldValues;
        }

        $headers = [
            'Api-Token' => $apiKey,
            'Content-Type' => 'application/json',
        ];

        try {
            // Create/update contact
            $contactResponse = Http::timeout(10)
                ->withHeaders($headers)
                ->post("{$apiUrl}/api/3/contact/sync", $contactData);

            if (!$contactResponse->successful()) {
                $error = $contactResponse->json();
                Log::error('ActiveCampaign contact creation failed', [
                    'status' => $contactResponse->status(),
                    'error' => $error['message'] ?? 'Unknown error',
                    'errors' => $error['errors'] ?? [],
                    'full_response' => $error,
                    'email' => $email,
                    'form' => $submission->form()->handle(),
                    'submission_id' => $submission->id(),
                ]);
                return;
            }

            $contactResult = $contactResponse->json();
            $contactId = $contactResult['contact']['id'] ?? null;

            if (!$contactId) {
                Log::error('ActiveCampaign: No contact ID returned', [
                    'response' => $contactResult,
                    'email' => $email,
                    'form' => $submission->form()->handle(),
                    'submission_id' => $submission->id(),
                ]);
                return;
            }

            // Subscribe to list
            $listData = [
                'contactList' => [
                    'list' => $listId,
                    'contact' => $contactId,
                    'status' => 1, // Active
                ]
            ];

            $listResponse = Http::timeout(10)
                ->withHeaders($headers)
                ->post("{$apiUrl}/api/3/contactLists", $listData);

            // Add tags if specified
            if ($tags) {
                $tagList = array_map('trim', explode(',', $tags));
                foreach ($tagList as $tag) {
                    $tagData = [
                        'contactTag' => [
                            'contact' => $contactId,
                            'tag' => $tag,
                        ]
                    ];

                    Http::timeout(10)
                        ->withHeaders($headers)
                        ->post("{$apiUrl}/api/3/contactTags", $tagData);
                }
            }

            Log::info('ActiveCampaign contact processed successfully', [
                'email' => $email,
                'contact_id' => $contactId,
                'list_id' => $listId,
                'form' => $submission->form()->handle(),
                'submission_id' => $submission->id(),
            ]);

        } catch (\Exception $e) {
            Log::error('ActiveCampaign connector exception', [
                'error' => $e->getMessage(),
                'email' => $email,
                'form' => $submission->form()->handle(),
                'submission_id' => $submission->id(),
            ]);
        }
    }
}
