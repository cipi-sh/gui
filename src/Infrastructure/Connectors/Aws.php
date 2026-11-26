<?php

namespace CipiGui\Infrastructure\Connectors;

use CipiGui\Infrastructure\Action;
use CipiGui\Infrastructure\Aws\AwsClient;
use CipiGui\Infrastructure\Aws\Lightsail;
use CipiGui\Infrastructure\Aws\Xml;
use CipiGui\Infrastructure\Column;
use CipiGui\Infrastructure\Connector;
use CipiGui\Infrastructure\DnsPublisher;
use CipiGui\Infrastructure\Field;
use CipiGui\Infrastructure\Outcome;
use CipiGui\Infrastructure\S3\S3Sections;
use CipiGui\Infrastructure\Section;
use CipiGui\Infrastructure\ServerTargets;
use Illuminate\Support\Str;

/**
 * Amazon Web Services, with an IAM access key per account: EC2 instances
 * (launch, power, images, type), key pairs, security groups, Elastic IPs,
 * EBS volumes and AMIs per region; Lightsail; RDS; S3 buckets; Route 53
 * zones and records; CloudFront invalidations; SES identities, quota and
 * suppressed addresses; CloudWatch alarms and logs; the cost of the month.
 * Lightsail, every kind of resource, lives in the Lightsail trait.
 */
final class Aws extends Connector
{
    use Lightsail;

    private const EC2 = '2016-11-15';

    private const RDS = '2014-10-31';

    private const ROUTE53 = 'https://route53.amazonaws.com/2013-04-01';

    private const CLOUDFRONT = 'https://cloudfront.amazonaws.com/2020-05-31';

    private const RETENTION = ['' => 'Never expire', '1' => '1 day', '3' => '3 days', '7' => '1 week', '14' => '2 weeks', '30' => '1 month', '60' => '2 months', '90' => '3 months', '180' => '6 months', '365' => '1 year', '731' => '2 years', '1827' => '5 years'];

    private const STATE = ['running' => 'green', 'available' => 'green', 'in-use' => 'blue', 'stopped' => 'zinc', 'terminated' => 'zinc', 'pending' => 'blue', 'stopping' => 'yellow', 'shutting-down' => 'yellow', 'deployed' => 'green', '*' => 'yellow'];

    public const REGIONS = [
        'us-east-1' => 'US East (N. Virginia)', 'us-east-2' => 'US East (Ohio)', 'us-west-1' => 'US West (N. California)', 'us-west-2' => 'US West (Oregon)',
        'ca-central-1' => 'Canada (Central)', 'sa-east-1' => 'South America (São Paulo)',
        'eu-west-1' => 'Europe (Ireland)', 'eu-west-2' => 'Europe (London)', 'eu-west-3' => 'Europe (Paris)', 'eu-central-1' => 'Europe (Frankfurt)',
        'eu-central-2' => 'Europe (Zurich)', 'eu-south-1' => 'Europe (Milan)', 'eu-south-2' => 'Europe (Spain)', 'eu-north-1' => 'Europe (Stockholm)',
        'me-central-1' => 'Middle East (UAE)', 'af-south-1' => 'Africa (Cape Town)', 'ap-south-1' => 'Asia Pacific (Mumbai)',
        'ap-southeast-1' => 'Asia Pacific (Singapore)', 'ap-southeast-2' => 'Asia Pacific (Sydney)', 'ap-northeast-1' => 'Asia Pacific (Tokyo)',
        'ap-northeast-2' => 'Asia Pacific (Seoul)', 'ap-northeast-3' => 'Asia Pacific (Osaka)',
    ];

    public function key(): string
    {
        return 'aws';
    }

    public function label(): string
    {
        return 'AWS';
    }

    public function description(): string
    {
        return __('EC2 (instances, key pairs, security groups, Elastic IPs, volumes, AMIs), all of Lightsail, RDS, S3 with its objects and app keys, Route 53, CloudFront, SES and CloudWatch, one AWS account per access key.');
    }

    public function icon(): string
    {
        return 'cloud';
    }

    public function alsoIn(): array
    {
        return [
            'storage' => __('S3: buckets, their objects with links that expire, CORS, and keys an app can use on one bucket only.'),
            'email' => __('SES: domains and addresses, their DKIM records published on Cloudflare, sending quota, suppressed addresses.'),
            'monitoring' => __('CloudWatch: alarms (mute, test, delete), log groups and their retention, recent log events.'),
        ];
    }

    public function docs(): string
    {
        return 'https://docs.aws.amazon.com/IAM/latest/UserGuide/id_credentials_access-keys.html';
    }

    public function fields(): array
    {
        return [
            Field::text('access_key_id', __('Access key ID'))->required()->placeholder('AKIA…'),
            Field::password('secret_access_key', __('Secret access key'))->required(),
            Field::select('region', __('Default region'), self::REGIONS)->required()->default('eu-central-1'),
            Field::password('session_token', __('Session token'))->help(__('Only for temporary credentials. Prefer an IAM user of its own with the policies the panel needs (EC2, Lightsail, RDS, S3, Route 53, CloudFront, SES, CloudWatch).')),
        ];
    }

    public function instructions(): array
    {
        return [
            __('AWS console → IAM → Users → Create user (no console access), e.g. cipi-panel.'),
            __('Permissions → Attach policies directly: AmazonEC2FullAccess, AmazonLightsailFullAccess, AmazonRDSFullAccess, AmazonS3FullAccess, AmazonRoute53FullAccess, CloudFrontFullAccess, AmazonSESFullAccess, CloudWatchFullAccess and AWSBillingReadOnlyAccess (or their ReadOnly counterparts for a read-only panel).'),
            __('"Key for an app" on a bucket makes an IAM user under /cipi/ with a policy for that bucket only: it needs IAMFullAccess, or a policy allowing iam:CreateUser, PutUserPolicy, CreateAccessKey, ListUsers, ListAccessKeys, DeleteAccessKey, DeleteUserPolicy and DeleteUser on arn:aws:iam::*:user/cipi/*.'),
            __('The user → Security credentials → Create access key → "Application running outside AWS": the key ID and the secret, shown once.'),
            __('The region here is the default one; EC2, Lightsail and RDS pages pick another from their filter.'),
        ];
    }

    public function client(array $credentials): AwsClient
    {
        return new AwsClient(
            self::string($credentials, 'access_key_id'),
            self::string($credentials, 'secret_access_key'),
            self::string($credentials, 'region', 'us-east-1'),
            self::string($credentials, 'session_token') ?: null,
        );
    }

    public function verify(object $api): string
    {
        /** @var AwsClient $api */
        $identity = data_get($api->query('sts', '2011-06-15', 'GetCallerIdentity'), 'GetCallerIdentityResult', []);

        return 'ok · '.data_get($identity, 'Account').' · '.Str::afterLast((string) data_get($identity, 'Arn'), ':');
    }

    public function sections(): array
    {
        return [
            $this->overview()->group(__('Account')),
            ...array_map(fn (Section $section) => $section->group('EC2'), [
                $this->instances(), $this->keyPairs(), $this->securityGroups(), $this->addresses(), $this->volumes(), $this->amis(),
            ]),
            ...array_map(fn (Section $section) => $section->group('Lightsail'), $this->lightsailSections()),
            $this->rds()->group(__('Databases')),
            $this->buckets()->group(__('Storage & CDN')),
            S3Sections::objects('s3-objects', 's3', fn (AwsClient $api) => $api->s3(), fn (AwsClient $api, array $scope) => (string) ($scope['region'] ?? '') !== '' ? (string) $scope['region'] : $this->bucketLocation($api, (string) $scope['bucket'])),
            S3Sections::appKeys('s3-keys', fn (AwsClient $api) => $api->iam())->group(__('Storage & CDN')),
            $this->distributions()->group(__('Storage & CDN')),
            $this->zones()->group('Route 53'),
            $this->recordSets()->group('Route 53'),
            $this->sesIdentities()->group('SES'),
            $this->sesAccount()->group('SES'),
            $this->sesSuppressed()->group('SES'),
            $this->alarms()->group('CloudWatch'),
            $this->logGroups()->group('CloudWatch'),
            $this->logEvents(),
        ];
    }

    private static function regionFilter(): Field
    {
        return Field::select('region', __('Region'), ['' => __('Account default'), ...self::REGIONS]);
    }

    /**
     * An EC2 call in the region of the scope.
     *
     * @param  array<string, mixed>  $scope
     * @param  array<string, scalar|null>  $params
     * @return array<mixed>
     */
    private static function ec2(AwsClient $api, array $scope, string $action, array $params = []): array
    {
        return $api->query('ec2', self::EC2, $action, $params, is_string($scope['region'] ?? null) ? $scope['region'] : null);
    }

    /** @param  array<string, mixed>  $row */
    private static function tag(array $row, string $key): string
    {
        foreach (Xml::list($row['tagSet'] ?? null) as $tag) {
            if (is_array($tag) && ($tag['key'] ?? null) === $key) {
                return (string) ($tag['value'] ?? '');
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $scope
     * @return array<string, string>
     */
    private static function instanceOptions(AwsClient $api, array $scope): array
    {
        return collect(self::instanceRows($api, $scope))
            ->reject(fn ($i) => data_get($i, 'instanceState.name') === 'terminated')
            ->mapWithKeys(fn ($i) => [(string) $i['instanceId'] => trim(self::tag($i, 'Name').' '.$i['instanceId'])])->all();
    }

    /**
     * @param  array<string, mixed>  $scope
     * @return list<array<string, mixed>>
     */
    private static function instanceRows(AwsClient $api, array $scope): array
    {
        $rows = [];
        $token = null;

        do {
            $answer = self::ec2($api, $scope, 'DescribeInstances', ['MaxResults' => 500, 'NextToken' => $token]);

            foreach (Xml::list($answer['reservationSet'] ?? null) as $reservation) {
                foreach (Xml::list(data_get($reservation, 'instancesSet')) as $instance) {
                    $rows[] = (array) $instance;
                }
            }

            $token = is_string($answer['nextToken'] ?? null) && $answer['nextToken'] !== '' ? $answer['nextToken'] : null;
        } while ($token !== null && count($rows) < 5000);

        return $rows;
    }

    private function overview(): Section
    {
        return Section::info('overview', __('Overview'), function (AwsClient $api) {
            $identity = data_get($api->query('sts', '2011-06-15', 'GetCallerIdentity'), 'GetCallerIdentityResult', []);

            return [
                __('Identity') => [
                    __('Account') => data_get($identity, 'Account'),
                    __('Signed in as') => data_get($identity, 'Arn'),
                    __('Default region') => $api->defaultRegion.' · '.(self::REGIONS[$api->defaultRegion] ?? ''),
                ],
            ];
        })->toolbar([
            Action::make('cost', __('Cost this month'))->icon('currency-dollar')->confirm(__('Cost Explorer charges $0.01 for each request. Ask?'))
                ->run(function (AwsClient $api) {
                    $answer = $api->json('ce', 'AWSInsightsIndexService.GetCostAndUsage', [
                        'TimePeriod' => ['Start' => now()->startOfMonth()->toDateString(), 'End' => now()->addDay()->toDateString()],
                        'Granularity' => 'MONTHLY',
                        'Metrics' => ['UnblendedCost'],
                        'GroupBy' => [['Type' => 'DIMENSION', 'Key' => 'SERVICE']],
                    ], 'us-east-1');

                    $services = collect(self::items(data_get($answer, 'ResultsByTime.0.Groups', [])))
                        ->mapWithKeys(fn ($g) => [(string) data_get($g, 'Keys.0') => round((float) data_get($g, 'Metrics.UnblendedCost.Amount', 0), 2)])
                        ->filter(fn ($amount) => $amount > 0)->sortDesc();

                    return Outcome::show(__('Cost this month (USD)'), ['total' => round($services->sum(), 2), 'by service' => $services->all()]);
                }),
        ]);
    }

    private function instances(): Section
    {
        $state = fn ($row) => data_get($row, 'instanceState.name');

        return Section::make('ec2', __('EC2 instances'))
            ->icon('server')
            ->id('instanceId')
            ->filters([self::regionFilter()])
            ->list(fn (AwsClient $api, array $scope) => self::instanceRows($api, $scope))
            ->columns([
                Column::make('name', __('Name'))->using(fn ($row) => self::tag($row, 'Name') ?: $row['instanceId']),
                Column::make('instanceState.name', __('State'))->badge(self::STATE),
                Column::make('ipAddress', __('Public IP'))->mono(),
                Column::make('privateIpAddress', __('Private IP'))->mono(),
                Column::make('instanceType', __('Type'))->mono(),
                Column::make('placement.availabilityZone', __('Zone')),
                Column::make('launchTime', __('Launched'))->date(),
            ])
            ->create(__('Launch an instance'), [
                Field::text('name', __('Name'))->required(),
                Field::select('image', __('Image'), fn (AwsClient $api, array $scope) => self::ubuntuImages($api, $scope))->help(__('The newest Ubuntu LTS published by Canonical in the region')),
                Field::text('image_id', __('Or another AMI id'))->placeholder('ami-…'),
                Field::text('type', __('Instance type'))->required()->default('t3.small')->help(__('t3.micro, t3.small, t4g.small (arm64 images), m7i.large, c7i.large…')),
                Field::select('key', __('Key pair'), fn (AwsClient $api, array $scope) => collect(Xml::list(self::ec2($api, $scope, 'DescribeKeyPairs')['keySet'] ?? null))
                    ->mapWithKeys(fn ($k) => [(string) $k['keyName'] => (string) $k['keyName']])->all()),
                Field::select('group', __('Security group'), fn (AwsClient $api, array $scope) => collect(Xml::list(self::ec2($api, $scope, 'DescribeSecurityGroups')['securityGroupInfo'] ?? null))
                    ->mapWithKeys(fn ($g) => [(string) $g['groupId'] => $g['groupName'].' · '.($g['vpcId'] ?? '')])->all())->help(__('Empty: the default group of the default VPC')),
                Field::select('subnet', __('Subnet'), fn (AwsClient $api, array $scope) => collect(Xml::list(self::ec2($api, $scope, 'DescribeSubnets')['subnetSet'] ?? null))
                    ->mapWithKeys(fn ($s) => [(string) $s['subnetId'] => trim(self::tag($s, 'Name').' '.$s['availabilityZone'].' '.$s['cidrBlock'])])->all()),
                Field::number('disk', __('Disk (GB)'))->default(20),
                Field::textarea('user_data', __('User data (cloud-init)')),
            ], function (AwsClient $api, array $row, array $data, array $scope) {
                $image = $data['image_id'] ?: $data['image'];

                if (! $image) {
                    return Outcome::done(__('Pick an image or type an AMI id.'));
                }

                $params = [
                    'ImageId' => $image,
                    'InstanceType' => $data['type'],
                    'MinCount' => 1,
                    'MaxCount' => 1,
                    'KeyName' => $data['key'],
                    'SubnetId' => $data['subnet'],
                    'SecurityGroupId.1' => $data['group'],
                    'UserData' => $data['user_data'] ? base64_encode($data['user_data']) : null,
                    'TagSpecification.1.ResourceType' => 'instance',
                    'TagSpecification.1.Tag.1.Key' => 'Name',
                    'TagSpecification.1.Tag.1.Value' => $data['name'],
                ];

                if ($data['disk']) {
                    $root = data_get(Xml::list(self::ec2($api, $scope, 'DescribeImages', ['ImageId.1' => $image])['imagesSet'] ?? null), '0.rootDeviceName', '/dev/sda1');
                    $params += ['BlockDeviceMapping.1.DeviceName' => $root, 'BlockDeviceMapping.1.Ebs.VolumeSize' => $data['disk'], 'BlockDeviceMapping.1.Ebs.VolumeType' => 'gp3', 'BlockDeviceMapping.1.Ebs.DeleteOnTermination' => 'true'];
                }

                $instance = data_get(Xml::list(self::ec2($api, $scope, 'RunInstances', $params)['instancesSet'] ?? null), '0.instanceId');

                return __('Instance :id is starting.', ['id' => $instance]);
            })
            ->actions([
                $this->ec2Action('StartInstances', __('Start'), 'play')->when(fn ($row) => $state($row) === 'stopped'),
                $this->ec2Action('StopInstances', __('Stop'), 'stop')->confirm(__('Stop the instance? Its public IP changes unless it has an Elastic IP.'))->when(fn ($row) => $state($row) === 'running'),
                $this->ec2Action('RebootInstances', __('Reboot'), 'arrow-path')->confirm(__('Reboot the instance?'))->when(fn ($row) => $state($row) === 'running'),
                Action::make('image', __('Create an image (AMI)'))->icon('camera')->fields([
                    Field::text('name', __('Name'))->required()->default(fn ($row) => (self::tag($row, 'Name') ?: $row['instanceId']).'-'.now()->format('Ymd-His')),
                    Field::checkbox('no_reboot', __('Without rebooting'))->default(true),
                ])->run(fn (AwsClient $api, array $row, array $data, array $scope) => __('Image :id is being created.', ['id' => self::ec2($api, $scope, 'CreateImage', [
                    'InstanceId' => $row['instanceId'], 'Name' => $data['name'], 'NoReboot' => $data['no_reboot'] ? 'true' : 'false',
                ])['imageId'] ?? ''])),
                Action::make('type', __('Change type'))->icon('arrows-pointing-out')->when(fn ($row) => $state($row) === 'stopped')
                    ->fields([Field::text('type', __('Instance type'))->required()->default(fn ($row) => $row['instanceType'] ?? '')])
                    ->run(fn (AwsClient $api, array $row, array $data, array $scope) => tap(__('Type changed.'), fn () => self::ec2($api, $scope, 'ModifyInstanceAttribute', ['InstanceId' => $row['instanceId'], 'InstanceType.Value' => $data['type']]))),
                Action::make('console', __('Console output'))->icon('command-line')
                    ->run(fn (AwsClient $api, array $row, array $data, array $scope) => Outcome::show(__('Console of :id', ['id' => $row['instanceId']]), base64_decode((string) (self::ec2($api, $scope, 'GetConsoleOutput', ['InstanceId' => $row['instanceId'], 'Latest' => 'true'])['output'] ?? '')) ?: __('No output yet.'))),
                Action::make('details', __('Details'))->icon('eye')->run(fn (AwsClient $api, array $row) => Outcome::show($row['instanceId'], $row)),
                Action::make('protect', __('Termination protection'))->icon('lock-closed')->fields([Field::checkbox('on', __('Protect from termination'))])
                    ->run(fn (AwsClient $api, array $row, array $data, array $scope) => tap(__('Saved.'), fn () => self::ec2($api, $scope, 'ModifyInstanceAttribute', ['InstanceId' => $row['instanceId'], 'DisableApiTermination.Value' => $data['on'] ? 'true' : 'false']))),
                $this->ec2Action('TerminateInstances', __('Terminate'), 'trash')->danger(__('Terminate the instance? Its root volume is deleted unless told otherwise.'))->when(fn ($row) => $state($row) !== 'terminated'),
            ]);
    }

    /** An action on one instance: Start, Stop, Reboot, Terminate. */
    private function ec2Action(string $action, string $label, string $icon): Action
    {
        return Action::make($action, $label)->icon($icon)->run(fn (AwsClient $api, array $row, array $data, array $scope) => tap(
            __(':action: sent to :id.', ['action' => $label, 'id' => $row['instanceId']]),
            fn () => self::ec2($api, $scope, $action, ['InstanceId.1' => $row['instanceId']]),
        ));
    }

    /**
     * The newest Ubuntu LTS images of Canonical in the region, one per release and architecture.
     *
     * @param  array<string, mixed>  $scope
     * @return array<string, string>
     */
    private static function ubuntuImages(AwsClient $api, array $scope): array
    {
        $images = Xml::list(self::ec2($api, $scope, 'DescribeImages', [
            'Owner.1' => '099720109477',
            'Filter.1.Name' => 'name',
            'Filter.1.Value.1' => 'ubuntu/images/hvm-ssd-gp3/ubuntu-noble-24.04-*-server-*',
            'Filter.1.Value.2' => 'ubuntu/images/hvm-ssd/ubuntu-jammy-22.04-*-server-*',
            'Filter.2.Name' => 'state',
            'Filter.2.Value.1' => 'available',
        ])['imagesSet'] ?? null);

        return collect($images)
            ->sortByDesc('creationDate')
            ->unique(fn ($image) => preg_replace('/-\d{8}(\.\d+)?$/', '', (string) $image['name']))
            ->mapWithKeys(fn ($image) => [(string) $image['imageId'] => Str::after((string) $image['name'], 'ubuntu/images/').' · '.$image['imageId']])
            ->all();
    }

    private function keyPairs(): Section
    {
        return Section::make('key-pairs', __('Key pairs'))
            ->icon('key')
            ->id('keyName')
            ->filters([self::regionFilter()])
            ->list(fn (AwsClient $api, array $scope) => Xml::list(self::ec2($api, $scope, 'DescribeKeyPairs')['keySet'] ?? null))
            ->columns([
                Column::make('keyName', __('Name')),
                Column::make('keyType', __('Type'))->badge(),
                Column::make('keyFingerprint', __('Fingerprint'))->mono(),
                Column::make('createTime', __('Created'))->date(),
            ])
            ->create(__('Import a public key'), [
                Field::text('name', __('Name'))->required(),
                Field::textarea('public_key', __('Public key'))->required()->placeholder('ssh-ed25519 AAAA…'),
            ], fn (AwsClient $api, array $row, array $data, array $scope) => tap(__('Key imported.'), fn () => self::ec2($api, $scope, 'ImportKeyPair', ['KeyName' => $data['name'], 'PublicKeyMaterial' => base64_encode(trim((string) $data['public_key']))])))
            ->toolbar([
                Action::make('generate', __('Generate a key pair'))->icon('sparkles')->fields([
                    Field::text('name', __('Name'))->required(),
                    Field::select('type', __('Type'), ['ed25519' => 'ED25519', 'rsa' => 'RSA'])->default('ed25519'),
                ])->run(fn (AwsClient $api, array $row, array $data, array $scope) => Outcome::show(
                    __('Private key of :name: save it now, AWS keeps no copy', ['name' => $data['name']]),
                    (string) (self::ec2($api, $scope, 'CreateKeyPair', ['KeyName' => $data['name'], 'KeyType' => $data['type']])['keyMaterial'] ?? ''),
                )),
            ])
            ->actions([
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the key pair? Instances keep the public key.'))
                    ->run(fn (AwsClient $api, array $row, array $data, array $scope) => tap(__('Key pair deleted.'), fn () => self::ec2($api, $scope, 'DeleteKeyPair', ['KeyName' => $row['keyName']]))),
            ]);
    }

    /**
     * The parameters of one inbound rule.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, scalar|null>
     */
    private static function permission(string $group, array $data): array
    {
        [$from, $to] = array_pad(explode('-', (string) $data['port'], 2), 2, null);
        $protocol = $data['protocol'] === 'all' ? '-1' : (string) $data['protocol'];

        return [
            'GroupId' => $group,
            'IpPermissions.1.IpProtocol' => $protocol,
            'IpPermissions.1.FromPort' => $protocol === '-1' ? null : ($protocol === 'icmp' ? -1 : (int) $from),
            'IpPermissions.1.ToPort' => $protocol === '-1' ? null : ($protocol === 'icmp' ? -1 : (int) ($to ?? $from)),
            ...(str_contains((string) $data['cidr'], ':')
                ? ['IpPermissions.1.Ipv6Ranges.1.CidrIpv6' => $data['cidr']]
                : ['IpPermissions.1.IpRanges.1.CidrIp' => $data['cidr']]),
        ];
    }

    private function securityGroups(): Section
    {
        $rule = [
            Field::select('protocol', __('Protocol'), ['tcp' => 'TCP', 'udp' => 'UDP', 'icmp' => 'ICMP', 'all' => __('All traffic')])->default('tcp'),
            Field::text('port', __('Port or range'))->default('443')->placeholder('22, 443, 8000-8100'),
            Field::text('cidr', __('From'))->required()->default('0.0.0.0/0')->help(__('An IPv4 or IPv6 CIDR: 0.0.0.0/0 is anywhere, 203.0.113.7/32 one address')),
        ];

        return Section::make('security-groups', __('Security groups'))
            ->icon('shield-check')
            ->id('groupId')
            ->filters([self::regionFilter()])
            ->list(fn (AwsClient $api, array $scope) => Xml::list(self::ec2($api, $scope, 'DescribeSecurityGroups')['securityGroupInfo'] ?? null))
            ->columns([
                Column::make('groupName', __('Name')),
                Column::make('groupId', __('Id'))->mono(),
                Column::make('vpcId', __('VPC'))->mono(),
                Column::make('inbound', __('Inbound'))->mono()->using(fn ($row) => collect(Xml::list($row['ipPermissions'] ?? null))->map(fn ($p) => ($p['ipProtocol'] === '-1' ? 'all' : ($p['fromPort'] ?? '').(($p['toPort'] ?? '') !== ($p['fromPort'] ?? '') ? '-'.$p['toPort'] : '').'/'.$p['ipProtocol'])
                    .' ← '.collect(Xml::list(data_get($p, 'ipRanges')))->pluck('cidrIp')->merge(collect(Xml::list(data_get($p, 'ipv6Ranges')))->pluck('cidrIpv6'))->implode(' '))->implode(', ')),
                Column::make('groupDescription', __('Description')),
            ])
            ->create(__('New security group'), [
                Field::text('name', __('Name'))->required(),
                Field::text('description', __('Description'))->required(),
                Field::select('vpc', __('VPC'), fn (AwsClient $api, array $scope) => collect(Xml::list(self::ec2($api, $scope, 'DescribeVpcs')['vpcSet'] ?? null))
                    ->mapWithKeys(fn ($v) => [(string) $v['vpcId'] => trim(self::tag($v, 'Name').' '.$v['vpcId'].' '.$v['cidrBlock'].(($v['isDefault'] ?? '') === 'true' ? ' (default)' : ''))])->all()),
            ], fn (AwsClient $api, array $row, array $data, array $scope) => __('Security group :id created.', ['id' => self::ec2($api, $scope, 'CreateSecurityGroup', ['GroupName' => $data['name'], 'GroupDescription' => $data['description'], 'VpcId' => $data['vpc']])['groupId'] ?? '']))
            ->actions([
                Action::make('open', __('Open a port'))->icon('lock-open')->fields($rule)
                    ->run(fn (AwsClient $api, array $row, array $data, array $scope) => tap(__('Rule added.'), fn () => self::ec2($api, $scope, 'AuthorizeSecurityGroupIngress', self::permission($row['groupId'], $data)))),
                Action::make('close', __('Close a port'))->icon('lock-closed')->fields($rule)
                    ->run(fn (AwsClient $api, array $row, array $data, array $scope) => tap(__('Rule removed.'), fn () => self::ec2($api, $scope, 'RevokeSecurityGroupIngress', self::permission($row['groupId'], $data)))),
                Action::make('rules', __('All rules'))->icon('eye')->run(fn (AwsClient $api, array $row) => Outcome::show($row['groupName'], ['inbound' => Xml::list($row['ipPermissions'] ?? null), 'outbound' => Xml::list($row['ipPermissionsEgress'] ?? null)])),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the security group? No instance may use it.'))
                    ->run(fn (AwsClient $api, array $row, array $data, array $scope) => tap(__('Security group deleted.'), fn () => self::ec2($api, $scope, 'DeleteSecurityGroup', ['GroupId' => $row['groupId']]))),
            ]);
    }

    private function addresses(): Section
    {
        return Section::make('elastic-ips', __('Elastic IPs'))
            ->icon('map-pin')
            ->id('allocationId')
            ->filters([self::regionFilter()])
            ->list(fn (AwsClient $api, array $scope) => Xml::list(self::ec2($api, $scope, 'DescribeAddresses')['addressesSet'] ?? null))
            ->columns([
                Column::make('publicIp', __('IP'))->mono(),
                Column::make('name', __('Name'))->using(fn ($row) => self::tag($row, 'Name')),
                Column::make('instanceId', __('Instance'))->mono(),
                Column::make('privateIpAddress', __('Private IP'))->mono(),
            ])
            ->toolbar([
                Action::make('allocate', __('Allocate an IP'))->icon('plus')->primary()
                    ->run(fn (AwsClient $api, array $row, array $data, array $scope) => __('Allocated :ip.', ['ip' => self::ec2($api, $scope, 'AllocateAddress', ['Domain' => 'vpc'])['publicIp'] ?? ''])),
            ])
            ->actions([
                Action::make('associate', __('Associate'))->icon('link')->fields([Field::select('instance', __('Instance'), fn (AwsClient $api, array $scope) => self::instanceOptions($api, $scope))->required()])
                    ->run(fn (AwsClient $api, array $row, array $data, array $scope) => tap(__('IP associated.'), fn () => self::ec2($api, $scope, 'AssociateAddress', ['AllocationId' => $row['allocationId'], 'InstanceId' => $data['instance']]))),
                Action::make('disassociate', __('Disassociate'))->icon('link-slash')->confirm(__('Disassociate the IP?'))->when(fn ($row) => ! empty($row['associationId']))
                    ->run(fn (AwsClient $api, array $row, array $data, array $scope) => tap(__('IP disassociated.'), fn () => self::ec2($api, $scope, 'DisassociateAddress', ['AssociationId' => $row['associationId']]))),
                Action::make('release', __('Release'))->icon('trash')->danger(__('Release the IP? It may never come back.'))->when(fn ($row) => empty($row['associationId']))
                    ->run(fn (AwsClient $api, array $row, array $data, array $scope) => tap(__('IP released.'), fn () => self::ec2($api, $scope, 'ReleaseAddress', ['AllocationId' => $row['allocationId']]))),
            ]);
    }

    private function volumes(): Section
    {
        return Section::make('volumes', __('EBS volumes'))
            ->icon('circle-stack')
            ->id('volumeId')
            ->filters([self::regionFilter()])
            ->list(fn (AwsClient $api, array $scope) => Xml::list(self::ec2($api, $scope, 'DescribeVolumes')['volumeSet'] ?? null))
            ->columns([
                Column::make('volumeId', __('Id'))->mono(),
                Column::make('name', __('Name'))->using(fn ($row) => self::tag($row, 'Name')),
                Column::make('size', __('Size (GB)')),
                Column::make('volumeType', __('Type'))->mono(),
                Column::make('status', __('Status'))->badge(self::STATE),
                Column::make('attached', __('Attached to'))->mono()->using(fn ($row) => collect(Xml::list($row['attachmentSet'] ?? null))->map(fn ($a) => $a['instanceId'].' '.$a['device'])->implode(', ')),
                Column::make('availabilityZone', __('Zone')),
            ])
            ->create(__('New volume'), [
                Field::text('zone', __('Availability zone'))->required()->placeholder('eu-central-1a'),
                Field::number('size', __('Size (GB)'))->required()->default(20),
                Field::select('type', __('Type'), ['gp3' => 'gp3', 'gp2' => 'gp2', 'io2' => 'io2', 'st1' => 'st1', 'sc1' => 'sc1'])->default('gp3'),
            ], fn (AwsClient $api, array $row, array $data, array $scope) => __('Volume :id created.', ['id' => self::ec2($api, $scope, 'CreateVolume', ['AvailabilityZone' => $data['zone'], 'Size' => $data['size'], 'VolumeType' => $data['type']])['volumeId'] ?? '']))
            ->actions([
                Action::make('attach', __('Attach'))->icon('link')->when(fn ($row) => ($row['status'] ?? '') === 'available')->fields([
                    Field::select('instance', __('Instance'), fn (AwsClient $api, array $scope) => self::instanceOptions($api, $scope))->required(),
                    Field::text('device', __('Device'))->required()->default('/dev/sdf'),
                ])->run(fn (AwsClient $api, array $row, array $data, array $scope) => tap(__('Attaching.'), fn () => self::ec2($api, $scope, 'AttachVolume', ['VolumeId' => $row['volumeId'], 'InstanceId' => $data['instance'], 'Device' => $data['device']]))),
                Action::make('detach', __('Detach'))->icon('link-slash')->confirm(__('Unmount it on the instance first. Detach?'))->when(fn ($row) => ($row['status'] ?? '') === 'in-use')
                    ->run(fn (AwsClient $api, array $row, array $data, array $scope) => tap(__('Detaching.'), fn () => self::ec2($api, $scope, 'DetachVolume', ['VolumeId' => $row['volumeId']]))),
                Action::make('snapshot', __('Snapshot'))->icon('camera')->fields([Field::text('description', __('Description'))->default(fn ($row) => $row['volumeId'].' '.now()->toDateTimeString())])
                    ->run(fn (AwsClient $api, array $row, array $data, array $scope) => __('Snapshot :id started.', ['id' => self::ec2($api, $scope, 'CreateSnapshot', ['VolumeId' => $row['volumeId'], 'Description' => $data['description']])['snapshotId'] ?? ''])),
                Action::make('resize', __('Resize'))->icon('arrows-pointing-out')->fields([Field::number('size', __('New size (GB)'))->required()->default(fn ($row) => $row['size'] ?? null)])
                    ->run(fn (AwsClient $api, array $row, array $data, array $scope) => tap(__('Resizing: grow the filesystem on the instance afterwards.'), fn () => self::ec2($api, $scope, 'ModifyVolume', ['VolumeId' => $row['volumeId'], 'Size' => $data['size']]))),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the volume and its data?'))->when(fn ($row) => ($row['status'] ?? '') === 'available')
                    ->run(fn (AwsClient $api, array $row, array $data, array $scope) => tap(__('Volume deleted.'), fn () => self::ec2($api, $scope, 'DeleteVolume', ['VolumeId' => $row['volumeId']]))),
            ]);
    }

    private function amis(): Section
    {
        return Section::make('amis', __('Images (AMI)'))
            ->icon('camera')
            ->id('imageId')
            ->filters([self::regionFilter()])
            ->list(fn (AwsClient $api, array $scope) => Xml::list(self::ec2($api, $scope, 'DescribeImages', ['Owner.1' => 'self'])['imagesSet'] ?? null))
            ->columns([
                Column::make('name', __('Name')),
                Column::make('imageId', __('Id'))->mono(),
                Column::make('imageState', __('State'))->badge(self::STATE),
                Column::make('architecture', __('Architecture')),
                Column::make('creationDate', __('Created'))->date(),
            ])
            ->actions([
                Action::make('deregister', __('Deregister'))->icon('trash')->danger(__('Deregister the image? Its snapshots stay until deleted.'))
                    ->run(fn (AwsClient $api, array $row, array $data, array $scope) => tap(__('Image deregistered.'), fn () => self::ec2($api, $scope, 'DeregisterImage', ['ImageId' => $row['imageId']]))),
            ]);
    }

    private function rds(): Section
    {
        $rds = fn (AwsClient $api, array $scope, string $action, array $params = []) => $api->query('rds', self::RDS, $action, $params, is_string($scope['region'] ?? null) ? $scope['region'] : null);
        $call = fn (string $action, string $label, string $icon) => Action::make($action, $label)->icon($icon)
            ->run(fn (AwsClient $api, array $row, array $data, array $scope) => tap(__(':action: sent to :name.', ['action' => $label, 'name' => $row['DBInstanceIdentifier']]), fn () => $rds($api, $scope, $action, ['DBInstanceIdentifier' => $row['DBInstanceIdentifier']])));

        return Section::make('rds', __('RDS'))
            ->icon('circle-stack')
            ->id('DBInstanceIdentifier')
            ->filters([self::regionFilter()])
            ->list(fn (AwsClient $api, array $scope) => Xml::list(data_get($rds($api, $scope, 'DescribeDBInstances'), 'DescribeDBInstancesResult.DBInstances.DBInstance')))
            ->columns([
                Column::make('DBInstanceIdentifier', __('Instance')),
                Column::make('DBInstanceStatus', __('Status'))->badge(self::STATE),
                Column::make('engine', __('Engine'))->using(fn ($row) => ($row['Engine'] ?? '').' '.($row['EngineVersion'] ?? '')),
                Column::make('DBInstanceClass', __('Class'))->mono(),
                Column::make('Endpoint.Address', __('Endpoint'))->mono(),
                Column::make('AllocatedStorage', __('Storage (GB)')),
                Column::make('MultiAZ', __('Multi-AZ')),
            ])
            ->actions([
                $call('StartDBInstance', __('Start'), 'play')->when(fn ($row) => ($row['DBInstanceStatus'] ?? '') === 'stopped'),
                $call('StopDBInstance', __('Stop'), 'stop')->confirm(__('Stop the database? AWS starts it again after 7 days.'))->when(fn ($row) => ($row['DBInstanceStatus'] ?? '') === 'available'),
                $call('RebootDBInstance', __('Reboot'), 'arrow-path')->confirm(__('Reboot the database?')),
                Action::make('snapshot', __('Snapshot'))->icon('camera')->fields([Field::text('snapshot', __('Snapshot name'))->required()->default(fn ($row) => $row['DBInstanceIdentifier'].'-'.now()->format('Ymd-His'))])
                    ->run(fn (AwsClient $api, array $row, array $data, array $scope) => tap(__('Snapshot started.'), fn () => $rds($api, $scope, 'CreateDBSnapshot', ['DBInstanceIdentifier' => $row['DBInstanceIdentifier'], 'DBSnapshotIdentifier' => $data['snapshot']]))),
                Action::make('class', __('Change class'))->icon('arrows-pointing-out')->fields([
                    Field::text('class', __('Instance class'))->required()->default(fn ($row) => $row['DBInstanceClass'] ?? ''),
                    Field::checkbox('now', __('Apply now (otherwise in the maintenance window)')),
                ])->run(fn (AwsClient $api, array $row, array $data, array $scope) => tap(__('Change requested.'), fn () => $rds($api, $scope, 'ModifyDBInstance', ['DBInstanceIdentifier' => $row['DBInstanceIdentifier'], 'DBInstanceClass' => $data['class'], 'ApplyImmediately' => $data['now'] ? 'true' : 'false']))),
                Action::make('details', __('Details'))->icon('eye')->run(fn (AwsClient $api, array $row) => Outcome::show($row['DBInstanceIdentifier'], $row)),
            ]);
    }

    private function buckets(): Section
    {
        return Section::make('s3', __('S3 buckets'))
            ->icon('archive-box')
            ->id('Name')
            ->list(fn (AwsClient $api) => Xml::list(data_get($api->rest('GET', 'https://s3.amazonaws.com/', 's3'), 'Buckets.Bucket')))
            ->columns([
                Column::make('Name', __('Bucket'))->mono(),
                Column::make('BucketRegion', __('Region')),
                Column::make('CreationDate', __('Created'))->date(),
            ])
            ->create(__('New bucket'), [
                Field::text('name', __('Name'))->required()->rules(['regex:/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/']),
                Field::select('region', __('Region'), self::REGIONS)->help(__('Empty: the account default')),
            ], function (AwsClient $api, array $row, array $data) {
                $region = $api->region($data['region']);
                $body = $region === 'us-east-1' ? '' : '<CreateBucketConfiguration xmlns="http://s3.amazonaws.com/doc/2006-03-01/"><LocationConstraint>'.$region.'</LocationConstraint></CreateBucketConfiguration>';
                $api->rest('PUT', "https://s3.{$region}.amazonaws.com/".$data['name'], 's3', $region, $body);

                return __('Bucket created, private by default.');
            })
            ->actions([
                ...S3Sections::bucketActions(
                    's3-objects',
                    fn (AwsClient $api) => $api->s3(),
                    fn (AwsClient $api, array $row) => is_string($row['BucketRegion'] ?? null) && $row['BucketRegion'] !== '' ? $row['BucketRegion'] : $this->bucketLocation($api, (string) $row['Name']),
                    fn (array $row) => (string) $row['Name'],
                    fn (AwsClient $api) => $api->iam(),
                    fn (string $bucket, string $region) => ['AWS_DEFAULT_REGION' => $region, 'AWS_BUCKET' => $bucket, 'AWS_USE_PATH_STYLE_ENDPOINT' => 'false'],
                ),
                Action::make('location', __('Region'))->icon('map-pin')->run(fn (AwsClient $api, array $row) => __(':bucket is in :region.', ['bucket' => $row['Name'], 'region' => $this->bucketLocation($api, $row['Name'])])),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the bucket? It must be empty.'))
                    ->run(function (AwsClient $api, array $row) {
                        $region = $this->bucketLocation($api, $row['Name']);
                        $api->rest('DELETE', "https://s3.{$region}.amazonaws.com/".$row['Name'], 's3', $region);

                        return __('Bucket deleted.');
                    }),
            ]);
    }

    /** Where a bucket lives: GetBucketLocation answers empty for us-east-1. */
    private function bucketLocation(AwsClient $api, string $bucket): string
    {
        $answer = $api->rest('GET', 'https://s3.amazonaws.com/'.rawurlencode($bucket).'?location', 's3');
        $region = is_string($answer[0] ?? null) ? $answer[0] : '';

        return match ($region) {
            '' => 'us-east-1',
            'EU' => 'eu-west-1',
            default => $region,
        };
    }

    /**
     * A Route 53 change: UPSERT or DELETE one record set.
     *
     * @param  array<string, mixed>  $set
     */
    private static function change(AwsClient $api, string $zone, string $action, array $set): void
    {
        $records = implode('', array_map(fn ($value) => '<ResourceRecord><Value>'.Xml::escape((string) $value).'</Value></ResourceRecord>', $set['values'] ?? []));
        $alias = $set['alias'] ?? null;

        $body = '<?xml version="1.0" encoding="UTF-8"?><ChangeResourceRecordSetsRequest xmlns="https://route53.amazonaws.com/doc/2013-04-01/"><ChangeBatch><Changes><Change>'
            .'<Action>'.$action.'</Action><ResourceRecordSet><Name>'.Xml::escape((string) $set['name']).'</Name><Type>'.Xml::escape((string) $set['type']).'</Type>'
            .(is_array($alias)
                ? '<AliasTarget><HostedZoneId>'.Xml::escape((string) ($alias['HostedZoneId'] ?? '')).'</HostedZoneId><DNSName>'.Xml::escape((string) ($alias['DNSName'] ?? '')).'</DNSName><EvaluateTargetHealth>'.Xml::escape((string) ($alias['EvaluateTargetHealth'] ?? 'false')).'</EvaluateTargetHealth></AliasTarget>'
                : '<TTL>'.(int) ($set['ttl'] ?? 300).'</TTL><ResourceRecords>'.$records.'</ResourceRecords>')
            .'</ResourceRecordSet></Change></Changes></ChangeBatch></ChangeResourceRecordSetsRequest>';

        $api->rest('POST', self::ROUTE53.'/hostedzone/'.$zone.'/rrset', 'route53', 'us-east-1', $body);
    }

    private function zones(): Section
    {
        return Section::make('route53', __('Route 53'))
            ->icon('globe-europe-africa')
            ->id(fn ($row) => Str::afterLast((string) ($row['Id'] ?? ''), '/'))
            ->list(function (AwsClient $api) {
                $rows = [];
                $marker = null;

                do {
                    $answer = $api->rest('GET', self::ROUTE53.'/hostedzone'.($marker ? '?marker='.rawurlencode($marker) : ''), 'route53');
                    $rows = [...$rows, ...Xml::list(data_get($answer, 'HostedZones.HostedZone'))];
                    $marker = ($answer['IsTruncated'] ?? 'false') === 'true' ? ($answer['NextMarker'] ?? null) : null;
                } while (is_string($marker) && $marker !== '');

                return $rows;
            })
            ->columns([
                Column::make('Name', __('Zone'))->mono(),
                Column::make('private', __('Private'))->using(fn ($row) => data_get($row, 'Config.PrivateZone') === 'true'),
                Column::make('ResourceRecordSetCount', __('Records')),
                Column::make('Config.Comment', __('Comment')),
            ])
            ->create(__('New hosted zone'), [
                Field::text('name', __('Domain'))->required()->placeholder('example.com'),
                Field::text('comment', __('Comment')),
            ], function (AwsClient $api, array $row, array $data) {
                $answer = $api->rest('POST', self::ROUTE53.'/hostedzone', 'route53', 'us-east-1', '<?xml version="1.0" encoding="UTF-8"?><CreateHostedZoneRequest xmlns="https://route53.amazonaws.com/doc/2013-04-01/">'
                    .'<Name>'.Xml::escape((string) $data['name']).'</Name><CallerReference>cipi-'.Str::uuid().'</CallerReference>'
                    .($data['comment'] ? '<HostedZoneConfig><Comment>'.Xml::escape((string) $data['comment']).'</Comment></HostedZoneConfig>' : '').'</CreateHostedZoneRequest>');

                return Outcome::show(__('Nameservers for :zone', ['zone' => $data['name']]), Xml::list(data_get($answer, 'DelegationSet.NameServers.NameServer')), __('Zone created: about $0.50 a month.'));
            })
            ->actions([
                Action::make('records', __('Records'))->icon('list-bullet')->opens('route53-records', fn ($row) => ['zone' => Str::afterLast((string) $row['Id'], '/'), 'zone_name' => rtrim((string) $row['Name'], '.')]),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the zone? Only its NS and SOA records may be left.'))
                    ->run(fn (AwsClient $api, array $row) => tap(__('Zone deleted.'), fn () => $api->rest('DELETE', self::ROUTE53.'/hostedzone/'.Str::afterLast((string) $row['Id'], '/'), 'route53'))),
            ]);
    }

    private function recordSets(): Section
    {
        $set = fn ($row) => [
            'name' => $row['Name'], 'type' => $row['Type'], 'ttl' => $row['TTL'] ?? null,
            'values' => collect(Xml::list(data_get($row, 'ResourceRecords.ResourceRecord')))->pluck('Value')->all(),
            'alias' => $row['AliasTarget'] ?? null,
        ];

        return Section::make('route53-records', __('Route 53 records'))
            ->childOf('route53', 'zone_name')
            ->id(fn ($row) => ($row['Name'] ?? '').'|'.($row['Type'] ?? '').'|'.($row['SetIdentifier'] ?? ''))
            ->list(function (AwsClient $api, array $scope) {
                $rows = [];
                $next = [];

                do {
                    $answer = $api->rest('GET', self::ROUTE53.'/hostedzone/'.$scope['zone'].'/rrset'.($next ? '?'.http_build_query($next, '', '&', PHP_QUERY_RFC3986) : ''), 'route53');
                    $rows = [...$rows, ...Xml::list(data_get($answer, 'ResourceRecordSets.ResourceRecordSet'))];
                    $next = ($answer['IsTruncated'] ?? 'false') === 'true' ? array_filter(['name' => $answer['NextRecordName'] ?? null, 'type' => $answer['NextRecordType'] ?? null]) : [];
                } while ($next !== []);

                return $rows;
            })
            ->columns([
                Column::make('Type', __('Type'))->badge(),
                Column::make('Name', __('Name'))->mono(),
                Column::make('values', __('Values'))->mono()->using(fn ($row) => isset($row['AliasTarget']) ? 'ALIAS '.data_get($row, 'AliasTarget.DNSName') : collect(Xml::list(data_get($row, 'ResourceRecords.ResourceRecord')))->pluck('Value')->implode(', ')),
                Column::make('TTL', __('TTL')),
            ])
            ->create(__('New record'), [
                Field::select('type', __('Type'), array_combine($types = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'CAA', 'PTR'], $types))->required()->default('A'),
                Field::text('name', __('Name'))->help(__('Empty for the zone itself, or www, api…')),
                Field::textarea('values', __('Values'))->required()->help(__('One per line — an address, or a server of the panel by its name (A, AAAA). TXT in quotes; MX as "10 mail.example.com"')),
                Field::number('ttl', __('TTL'))->default(300),
            ], fn (AwsClient $api, array $row, array $data, array $scope) => tap(__('Record saved.'), fn () => self::change($api, $scope['zone'], 'UPSERT', [
                'name' => ltrim(($data['name'] ? $data['name'].'.' : '').$scope['zone_name'], '.'), 'type' => $data['type'], 'ttl' => $data['ttl'], 'values' => ServerTargets::resolveAll($data['type'], self::lines($data['values'])),
            ])))
            ->actions([
                Action::make('edit', __('Edit values'))->icon('pencil')->when(fn ($row) => ! isset($row['AliasTarget']))->fields([
                    Field::textarea('values', __('Values'))->required()->default(fn ($row) => collect(Xml::list(data_get($row, 'ResourceRecords.ResourceRecord')))->pluck('Value')->implode("\n")),
                    Field::number('ttl', __('TTL'))->default(fn ($row) => $row['TTL'] ?? 300),
                ])->run(fn (AwsClient $api, array $row, array $data, array $scope) => tap(__('Record saved.'), fn () => self::change($api, $scope['zone'], 'UPSERT', [...$set($row), 'ttl' => $data['ttl'], 'values' => ServerTargets::resolveAll($row['Type'] ?? null, self::lines($data['values']))]))),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete this record set?'))->when(fn ($row) => ! in_array($row['Type'] ?? '', ['SOA'], true))
                    ->run(fn (AwsClient $api, array $row, array $data, array $scope) => tap(__('Record deleted.'), fn () => self::change($api, $scope['zone'], 'DELETE', $set($row)))),
            ]);
    }

    private function distributions(): Section
    {
        return Section::make('cloudfront', __('CloudFront'))
            ->icon('bolt')
            ->id('Id')
            ->list(fn (AwsClient $api) => Xml::list(data_get($api->rest('GET', self::CLOUDFRONT.'/distribution', 'cloudfront'), 'Items.DistributionSummary')))
            ->columns([
                Column::make('Id', __('Id'))->mono(),
                Column::make('DomainName', __('Address'))->mono(),
                Column::make('aliases', __('Aliases'))->mono()->using(fn ($row) => implode(', ', array_map('strval', Xml::list(data_get($row, 'Aliases.Items.CNAME'))))),
                Column::make('Status', __('Status'))->badge(self::STATE),
                Column::make('Enabled', __('Enabled')),
                Column::make('Comment', __('Comment')),
            ])
            ->actions([
                Action::make('invalidate', __('Invalidate'))->icon('fire')->fields([Field::textarea('paths', __('Paths'))->required()->default('/*')->help(__('One per line; /* is everything'))])
                    ->run(function (AwsClient $api, array $row, array $data) {
                        $paths = self::lines($data['paths']);
                        $api->rest('POST', self::CLOUDFRONT.'/distribution/'.$row['Id'].'/invalidation', 'cloudfront', 'us-east-1', '<?xml version="1.0" encoding="UTF-8"?><InvalidationBatch xmlns="http://cloudfront.amazonaws.com/doc/2020-05-31/">'
                            .'<Paths><Quantity>'.count($paths).'</Quantity><Items>'.implode('', array_map(fn ($p) => '<Path>'.Xml::escape($p).'</Path>', $paths)).'</Items></Paths>'
                            .'<CallerReference>cipi-'.Str::uuid().'</CallerReference></InvalidationBatch>');

                        return __('Invalidation started.');
                    }),
                Action::make('details', __('Details'))->icon('eye')->run(fn (AwsClient $api, array $row) => Outcome::show($row['Id'], $row)),
            ]);
    }

    /**
     * An SES v2 call (JSON over REST) in the region of the scope.
     *
     * @param  array<string, mixed>  $scope
     * @param  array<string, mixed>|null  $payload
     * @return array<mixed>
     */
    private static function ses(AwsClient $api, array $scope, string $method, string $path, ?array $payload = null): array
    {
        return $api->restJson($method, 'email', '/v2/email'.$path, $payload, is_string($scope['region'] ?? null) ? $scope['region'] : null);
    }

    /**
     * The three DKIM CNAMEs SES asks of a domain.
     *
     * @param  array<string, mixed>  $identity  GetEmailIdentity's answer
     * @return list<array<string, string>>
     */
    private static function sesRecords(string $domain, array $identity): array
    {
        return array_map(fn ($token) => [
            'type' => 'CNAME',
            'name' => $token.'._domainkey.'.$domain,
            'content' => $token.'.dkim.amazonses.com',
        ], array_values(array_filter((array) data_get($identity, 'DkimAttributes.Tokens', []), 'is_string')));
    }

    private function sesIdentities(): Section
    {
        $records = fn (AwsClient $api, array $row, array $scope) => [
            (string) $row['IdentityName'],
            self::sesRecords((string) $row['IdentityName'], self::ses($api, ['region' => $row['_region'] ?? $scope['region'] ?? null], 'GET', '/identities/'.rawurlencode((string) $row['IdentityName']))),
        ];

        return Section::make('ses', __('SES identities'))
            ->icon('mail')
            ->id('IdentityName')
            ->description(__('Domains and addresses SES may send from. A domain is verified by its DKIM records: "Publish DNS on Cloudflare" writes them.'))
            ->filters([self::regionFilter()])
            ->list(function (AwsClient $api, array $scope) {
                $region = $api->region($scope['region'] ?? null);

                return array_map(
                    fn ($i) => [...(array) $i, '_region' => $region],
                    array_values(array_filter((array) (self::ses($api, $scope, 'GET', '/identities?PageSize=1000')['EmailIdentities'] ?? []), 'is_array')),
                );
            })
            ->columns([
                Column::make('IdentityName', __('Identity'))->mono(),
                Column::make('IdentityType', __('Type'))->badge(['domain' => 'blue', 'email_address' => 'zinc', '*' => 'zinc']),
                Column::make('VerificationStatus', __('Verification'))->badge(['success' => 'green', 'pending' => 'yellow', 'failed' => 'red', 'temporary_failure' => 'yellow', '*' => 'zinc']),
                Column::make('SendingEnabled', __('Sending'))->bool(),
            ])
            ->create(__('New identity'), [
                Field::text('identity', __('Domain or address'))->required()->placeholder('example.com')->help(__('A domain verifies with DKIM records; an address with the link SES mails to it.')),
            ], function (AwsClient $api, array $row, array $data, array $scope) {
                $identity = strtolower(trim((string) $data['identity']));
                $answer = self::ses($api, $scope, 'POST', '/identities', ['EmailIdentity' => $identity]);

                if (str_contains($identity, '@')) {
                    return __('SES mailed a verification link to :address.', ['address' => $identity]);
                }

                return Outcome::show(__('DNS for :domain', ['domain' => $identity]), DnsPublisher::text(self::sesRecords($identity, $answer)), __('Domain added: publish its DKIM records.'));
            })
            ->actions([
                Action::make('dns', __('DNS records'))->icon('globe')->when(fn ($row) => ($row['IdentityType'] ?? '') === 'DOMAIN')
                    ->run(function (AwsClient $api, array $row, array $data, array $scope) use ($records) {
                        [$domain, $list] = $records($api, $row, $scope);

                        return Outcome::show(__('DNS for :domain', ['domain' => $domain]), DnsPublisher::text($list));
                    }),
                DnsPublisher::action($records)->when(fn ($row) => ($row['IdentityType'] ?? '') === 'DOMAIN' && DnsPublisher::available()),
                Action::make('details', __('Details'))->icon('eye')
                    ->run(fn (AwsClient $api, array $row, array $data, array $scope) => Outcome::show((string) $row['IdentityName'], self::ses($api, ['region' => $row['_region'] ?? null], 'GET', '/identities/'.rawurlencode((string) $row['IdentityName'])))),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the identity? SES stops sending from it.'))
                    ->run(fn (AwsClient $api, array $row) => tap(__('Identity deleted.'), fn () => self::ses($api, ['region' => $row['_region'] ?? null], 'DELETE', '/identities/'.rawurlencode((string) $row['IdentityName'])))),
            ]);
    }

    private function sesAccount(): Section
    {
        return Section::info('ses-account', __('SES sending'), function (AwsClient $api, array $scope) {
            $account = self::ses($api, $scope, 'GET', '/account');

            return [
                __('Account') => [
                    __('Region') => $api->region($scope['region'] ?? null),
                    __('Out of the sandbox') => (bool) ($account['ProductionAccessEnabled'] ?? false),
                    __('Sending enabled') => (bool) ($account['SendingEnabled'] ?? false),
                    __('Enforcement') => (string) ($account['EnforcementStatus'] ?? ''),
                ],
                __('Quota') => [
                    __('Per 24 hours') => data_get($account, 'SendQuota.Max24HourSend'),
                    __('Sent in the last 24 hours') => data_get($account, 'SendQuota.SentLast24Hours'),
                    __('Per second') => data_get($account, 'SendQuota.MaxSendRate'),
                ],
            ];
        })->filters([self::regionFilter()]);
    }

    private function sesSuppressed(): Section
    {
        return Section::make('ses-suppressed', __('SES suppressed'))
            ->icon('x-circle')
            ->id('EmailAddress')
            ->description(__('Addresses SES no longer sends to, after a bounce or a complaint.'))
            ->filters([self::regionFilter()])
            ->list(function (AwsClient $api, array $scope) {
                $region = $api->region($scope['region'] ?? null);

                return array_map(fn ($r) => [...(array) $r, '_region' => $region], array_values(array_filter((array) (self::ses($api, $scope, 'GET', '/suppression/addresses?PageSize=1000')['SuppressedDestinationSummaries'] ?? []), 'is_array')));
            })
            ->columns([
                Column::make('EmailAddress', __('Address'))->mono(),
                Column::make('Reason', __('Reason'))->badge(['bounce' => 'red', 'complaint' => 'yellow', '*' => 'zinc']),
                Column::make('LastUpdateTime', __('Since'))->date(),
            ])
            ->actions([
                Action::make('delete', __('Send to it again'))->icon('arrow-uturn-left')->confirm(__('Remove the address from the suppression list? SES sends to it again.'))
                    ->run(fn (AwsClient $api, array $row) => tap(__('Removed from the list.'), fn () => self::ses($api, ['region' => $row['_region'] ?? null], 'DELETE', '/suppression/addresses/'.rawurlencode((string) $row['EmailAddress'])))),
            ]);
    }

    /**
     * @param  array<string, mixed>  $scope
     * @return list<array<string, mixed>>
     */
    private static function alarmRows(AwsClient $api, array $scope): array
    {
        $rows = [];
        $token = null;
        $region = $api->region($scope['region'] ?? null);

        do {
            $answer = $api->query('monitoring', '2010-08-01', 'DescribeAlarms', ['MaxRecords' => 100, 'NextToken' => $token], $region);
            foreach (Xml::list(data_get($answer, 'DescribeAlarmsResult.MetricAlarms')) as $alarm) {
                $rows[] = [...(array) $alarm, '_region' => $region];
            }
            $next = data_get($answer, 'DescribeAlarmsResult.NextToken');
            $token = is_string($next) && $next !== '' ? $next : null;
        } while ($token !== null && count($rows) < 2000);

        return $rows;
    }

    private function alarms(): Section
    {
        $call = fn (AwsClient $api, array $row, string $action, array $params = []) => $api->query('monitoring', '2010-08-01', $action, ['AlarmNames.member.1' => (string) $row['AlarmName'], ...$params], (string) ($row['_region'] ?? '') ?: null);

        return Section::make('cw-alarms', __('Alarms'))
            ->icon('bell-alert')
            ->id('AlarmName')
            ->filters([self::regionFilter()])
            ->list(fn (AwsClient $api, array $scope) => self::alarmRows($api, $scope))
            ->columns([
                Column::make('AlarmName', __('Alarm')),
                Column::make('StateValue', __('State'))->badge(['ok' => 'green', 'alarm' => 'red', 'insufficient_data' => 'zinc']),
                Column::make('metric', __('Metric'))->using(fn ($row) => trim(($row['Namespace'] ?? '').' · '.($row['MetricName'] ?? ''), ' ·')),
                Column::make('ActionsEnabled', __('Actions'))->using(fn ($row) => ($row['ActionsEnabled'] ?? '') === 'true' ? __('on') : __('off')),
                Column::make('StateUpdatedTimestamp', __('Since'))->date(),
            ])
            ->actions([
                Action::make('reason', __('Why'))->icon('info')
                    ->run(fn (AwsClient $api, array $row) => Outcome::show((string) $row['AlarmName'], (string) ($row['StateReason'] ?? ''))),
                Action::make('mute', __('Turn actions off'))->icon('pause')->when(fn ($row) => ($row['ActionsEnabled'] ?? '') === 'true')
                    ->run(function (AwsClient $api, array $row) {
                        $api->query('monitoring', '2010-08-01', 'DisableAlarmActions', ['AlarmNames.member.1' => (string) $row['AlarmName']], (string) ($row['_region'] ?? '') ?: null);

                        return __('The alarm no longer notifies.');
                    }),
                Action::make('unmute', __('Turn actions on'))->icon('play')->when(fn ($row) => ($row['ActionsEnabled'] ?? '') !== 'true')
                    ->run(function (AwsClient $api, array $row) {
                        $api->query('monitoring', '2010-08-01', 'EnableAlarmActions', ['AlarmNames.member.1' => (string) $row['AlarmName']], (string) ($row['_region'] ?? '') ?: null);

                        return __('The alarm notifies again.');
                    }),
                Action::make('test', __('Test the notification'))->icon('bell-alert')->confirm(__('Set the alarm to ALARM for a moment? Its actions run as for a real alarm.'))
                    ->run(function (AwsClient $api, array $row) {
                        $api->query('monitoring', '2010-08-01', 'SetAlarmState', ['AlarmName' => (string) $row['AlarmName'], 'StateValue' => 'ALARM', 'StateReason' => 'Test from the Cipi panel'], (string) ($row['_region'] ?? '') ?: null);

                        return __('Set to ALARM: it goes back with the next evaluation.');
                    }),
                Action::make('delete', __('Delete'))->icon('trash')->danger()
                    ->run(fn (AwsClient $api, array $row) => tap(__('Alarm deleted.'), fn () => $call($api, $row, 'DeleteAlarms'))),
            ]);
    }

    /**
     * A CloudWatch Logs call in a region.
     *
     * @param  array<string, mixed>  $payload
     * @return array<mixed>
     */
    private static function logs(AwsClient $api, ?string $region, string $action, array $payload = []): array
    {
        return $api->json('logs', 'Logs_20140328.'.$action, $payload, $region !== '' ? $region : null);
    }

    private function logGroups(): Section
    {
        return Section::make('cw-logs', __('Log groups'))
            ->icon('document-text')
            ->id('logGroupName')
            ->filters([self::regionFilter()])
            ->list(function (AwsClient $api, array $scope) {
                $region = $api->region($scope['region'] ?? null);
                $rows = [];
                $token = null;

                do {
                    $answer = self::logs($api, $region, 'DescribeLogGroups', array_filter(['limit' => 50, 'nextToken' => $token]));
                    foreach ((array) ($answer['logGroups'] ?? []) as $group) {
                        $rows[] = [...(array) $group, '_region' => $region];
                    }
                    $token = is_string($answer['nextToken'] ?? null) ? $answer['nextToken'] : null;
                } while ($token !== null && count($rows) < 1000);

                return $rows;
            })
            ->columns([
                Column::make('logGroupName', __('Group'))->mono(),
                Column::make('retentionInDays', __('Kept'))->using(fn ($row) => isset($row['retentionInDays']) ? __(':n days', ['n' => $row['retentionInDays']]) : __('forever')),
                Column::make('storedBytes', __('Stored'))->using(fn ($row) => S3Sections::size((int) ($row['storedBytes'] ?? 0))),
                Column::make('created', __('Created'))->date()->using(fn ($row) => isset($row['creationTime']) ? gmdate('c', intdiv((int) $row['creationTime'], 1000)) : ''),
            ])
            ->create(__('New log group'), [
                Field::text('name', __('Name'))->required()->placeholder('/cipi/myapp'),
                Field::select('retention', __('Keep logs'), self::RETENTION)->default('30'),
            ], function (AwsClient $api, array $row, array $data, array $scope) {
                $region = $api->region($scope['region'] ?? null);
                self::logs($api, $region, 'CreateLogGroup', ['logGroupName' => $data['name']]);

                if (($data['retention'] ?? '') !== '' && $data['retention'] !== null) {
                    self::logs($api, $region, 'PutRetentionPolicy', ['logGroupName' => $data['name'], 'retentionInDays' => (int) $data['retention']]);
                }

                return __('Log group created.');
            })
            ->actions([
                Action::make('events', __('Recent events'))->icon('list-bullet')
                    ->opens('cw-events', fn (array $row) => ['group' => (string) $row['logGroupName'], 'region' => (string) ($row['_region'] ?? '')]),
                Action::make('retention', __('How long to keep'))->icon('clock')
                    ->fields([Field::select('retention', __('Keep logs'), self::RETENTION)->default(fn ($row) => isset($row['retentionInDays']) ? (string) $row['retentionInDays'] : '')])
                    ->run(function (AwsClient $api, array $row, array $data) {
                        $region = (string) ($row['_region'] ?? '');
                        ($data['retention'] ?? '') === '' || $data['retention'] === null
                            ? self::logs($api, $region, 'DeleteRetentionPolicy', ['logGroupName' => $row['logGroupName']])
                            : self::logs($api, $region, 'PutRetentionPolicy', ['logGroupName' => $row['logGroupName'], 'retentionInDays' => (int) $data['retention']]);

                        return __('Retention saved.');
                    }),
                Action::make('delete', __('Delete'))->icon('trash')->danger(__('Delete the log group and every log in it?'))
                    ->run(fn (AwsClient $api, array $row) => tap(__('Log group deleted.'), fn () => self::logs($api, (string) ($row['_region'] ?? ''), 'DeleteLogGroup', ['logGroupName' => $row['logGroupName']]))),
            ]);
    }

    private function logEvents(): Section
    {
        return Section::make('cw-events', __('Log events'))
            ->childOf('cw-logs', 'group')
            ->id('eventId')
            ->filters([
                Field::select('since', __('Since'), ['900' => __('15 minutes'), '3600' => __('1 hour'), '21600' => __('6 hours'), '86400' => __('24 hours'), '604800' => __('7 days')])->default('3600'),
                Field::text('pattern', __('Filter'))->placeholder('ERROR'),
            ])
            ->list(function (AwsClient $api, array $scope) {
                $since = max(60, (int) ($scope['since'] ?? 3600));
                $events = [];
                $token = null;
                $pages = 0;

                do {
                    $answer = self::logs($api, (string) ($scope['region'] ?? ''), 'FilterLogEvents', array_filter([
                        'logGroupName' => (string) $scope['group'],
                        'startTime' => (time() - $since) * 1000,
                        'filterPattern' => ($scope['pattern'] ?? '') !== '' ? (string) $scope['pattern'] : null,
                        'limit' => 500,
                        'nextToken' => $token,
                    ], fn ($v) => $v !== null));
                    $events = [...$events, ...array_values(array_filter((array) ($answer['events'] ?? []), 'is_array'))];
                    $token = is_string($answer['nextToken'] ?? null) ? $answer['nextToken'] : null;
                } while ($token !== null && ++$pages < 6);

                // The newest first, the last 500.
                return array_map(fn ($e) => [...$e, 'at' => gmdate('c', intdiv((int) ($e['timestamp'] ?? 0), 1000))], array_slice(array_reverse($events), 0, 500));
            })
            ->columns([
                Column::make('at', __('When'))->date(),
                Column::make('logStreamName', __('Stream'))->mono(),
                Column::make('message', __('Message'))->mono()->using(fn ($row) => mb_substr(trim((string) ($row['message'] ?? '')), 0, 300)),
            ])
            ->actions([
                Action::make('show', __('Whole event'))->icon('eye')->run(fn (AwsClient $api, array $row) => Outcome::show((string) ($row['logStreamName'] ?? ''), (string) ($row['message'] ?? ''))),
            ]);
    }
}
