<?php
App::uses('AppModel', 'Model');

class OverCorrelatingValue extends AppModel
{
    public $recursive = -1;

    public function beforeValidate($options = array())
    {
        $this->data['OverCorrelatingValue']['value'] = self::truncate($this->data['OverCorrelatingValue']['value']);
        return true;
    }

    public function beforeSave($options = array())
    {
        $this->data['OverCorrelatingValue']['value'] = self::truncate($this->data['OverCorrelatingValue']['value']);
        return true;
    }

    public static function truncate(string $value): string
    {
        return mb_substr($value, 0, 191);
    }

    public static function truncateValues(array $values): array
    {
        return array_map(function(string $value) {
            return self::truncate($value);
        }, $values);
    }

    /**
     * @param string $value
     * @param int $count
     * @return void
     * @throws Exception
     */
    public function block($value, $count = 0)
    {
        $this->unblock($value);
        $this->create();
        $this->save(
            [
                'value' => $value,
                'occurrence' => $count
            ]
        );
    }

    /**
     * @param string $value
     * @return void
     */
    public function unBlock($value)
    {
        $this->deleteAll(
            [
                'OverCorrelatingValue.value' => self::truncate($value)
            ],
            false
        );
    }

    /**
     * @return int
     */
    public function getLimit()
    {
        return Configure::read('MISP.correlation_limit') ?: 20;
    }

    public function getOverCorrelations($query)
    {
        $data = $this->find('all', $query);
        $limit = $this->getLimit();
        foreach ($data as $k => $v) {
            if ($v['OverCorrelatingValue']['occurrence'] >= $limit) {
                $data[$k]['OverCorrelatingValue']['over_correlation'] = true;
            } else {
                $data[$k]['OverCorrelatingValue']['over_correlation'] = false;
            }
        }
        return $data;
    }

    public function findOverCorrelatingValues(array $valuesToCheck): array
    {
        $valuesToCheck = array_unique(self::truncateValues($valuesToCheck), SORT_REGULAR);
        return $this->find('column', [
            'conditions' => ['value' => $valuesToCheck],
            'fields' => ['value'],
        ]);
    }

    public function generateOccurrencesRouter()
    {
        if (Configure::read('MISP.background_jobs')) {
            /** @var Job $job */
            $job = ClassRegistry::init('Job');
            $jobId = $job->createJob(
                'SYSTEM',
                Job::WORKER_DEFAULT,
                'generateOccurrences',
                '',
                'Starting populating the occurrences field for the over correlating values.'
            );

            $this->getBackgroundJobsTool()->enqueue(
                BackgroundJobsTool::DEFAULT_QUEUE,
                BackgroundJobsTool::CMD_ADMIN,
                [
                    'jobGenerateOccurrences',
                    $jobId
                ],
                true,
                $jobId
            );

            return $jobId;
        } else {
            return $this->generateOccurrences();
        }
    }

    public function generateOccurrences()
    {
        $overCorrelations = $this->find('all', [
            'recursive' => -1
        ]);
        $this->Attribute = ClassRegistry::init('Attribute');
        foreach ($overCorrelations as &$overCorrelation) {
            $value = $overCorrelation['OverCorrelatingValue']['value'] . '%';
            $count = $this->Attribute->find('count', [
                'recursive' => -1,
                'conditions' => [
                    'OR' => [
                        'Attribute.value1 LIKE' => $value,
                        'AND' => [
                            'Attribute.value2 LIKE' => $value,
                            'NOT' => ['Attribute.type' => Attribute::PRIMARY_ONLY_CORRELATING_TYPES]
                        ],
                    ],
                    'NOT' => ['Attribute.type' => Attribute::NON_CORRELATING_TYPES],
                    'Attribute.disable_correlation' => 0,
                    'Event.disable_correlation' => 0,
                    'Attribute.deleted' => 0,
                ],
                'contain' => ['Event'],
            ]);
            $overCorrelation['OverCorrelatingValue']['occurrence'] = $count;
        }
        $this->saveMany($overCorrelations);
    }
}
