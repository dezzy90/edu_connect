<?php

use App\Services\Biometrics\Hikvision\EnrollmentCapabilities;
use App\Services\Biometrics\Hikvision\PushCommands;

it('retains only bounded enrollment capacity operations and counts', function (): void {
    $entry = ['dataFormat' => 'jsonData'];
    $summary = EnrollmentCapabilities::summary($entry, json_encode(['UserInfoCap' => [
        'maxRecordNum' => 1500, 'supportFunction' => 'get,post,setUp,delete,SECRET',
        'password' => 'SECRET', 'name' => 'PRIVATE NAME', 'photo' => 'IMAGE',
    ]]), PushCommands::ENROLLMENT_DISCOVERY_URLS[0]);
    expect($summary)->toBe(['received' => true, 'failed' => false, 'maxRecordNum' => 1500,
        'supportFunction' => ['get', 'post', 'delete', 'setUp'], 'recognized' => true]);
    expect(json_encode($summary))->not->toContain('SECRET', 'PRIVATE NAME', 'IMAGE');
    expect(EnrollmentCapabilities::summary($entry, '{"UserInfoCount":{"userNumber":1}}', PushCommands::ENROLLMENT_DISCOVERY_URLS[1]))
        ->toHaveKey('userNumber', 1);
    expect(EnrollmentCapabilities::summary($entry, '{"FDRecordDataMaxNum":1500,"FDMaxNum":2,"supportFunction":"get,post,setUp"}', PushCommands::ENROLLMENT_DISCOVERY_URLS[2]))
        ->toHaveKey('FDRecordDataMaxNum', 1500)->toHaveKey('FDMaxNum', 2);
});

it('retains face library identifiers but not their labels or custom information', function (): void {
    $summary = EnrollmentCapabilities::summary(['dataFormat' => 'jsonData'], json_encode(['FDLib' => [
        ['FDID' => '1', 'faceLibType' => 'blackFD', 'name' => 'PRIVATE', 'customInfo' => 'SECRET'],
    ]]), PushCommands::ENROLLMENT_DISCOVERY_URLS[3]);
    expect($summary['libraries'])->toBe([['FDID' => '1', 'faceLibType' => 'blackFD']]);
    expect(json_encode($summary))->not->toContain('PRIVATE', 'SECRET');
});

it('does not treat wrong types or arbitrary successful JSON as verified capacity', function (): void {
    $summary = EnrollmentCapabilities::summary(['dataFormat' => 'jsonData'], '{"maxRecordNum":"1500","supportFunction":["post",5],"arbitrary":"SECRET"}', PushCommands::ENROLLMENT_DISCOVERY_URLS[0]);
    expect($summary)->toBe(['received' => true, 'failed' => false, 'recognized' => false]);
});

it('accepts documented capability option containers without retaining arbitrary values', function (): void {
    $summary = EnrollmentCapabilities::summary(['dataFormat' => 'jsonData'], '{"UserInfo":{"maxRecordNum":1500,"supportFunction":{"@opt":"get,post,setUp,PRIVATE"}}}', PushCommands::ENROLLMENT_DISCOVERY_URLS[0]);
    expect($summary['supportFunction'])->toBe(['get', 'post', 'setUp']);
    expect(json_encode($summary))->not->toContain('PRIVATE');
    expect(EnrollmentCapabilities::summary(['dataFormat' => 'jsonData'], '{"FaceRecognizeMode":{"mode":"normalMode"}}', PushCommands::ENROLLMENT_DISCOVERY_URLS[4]))
        ->toHaveKey('mode', 'normalMode');
});

it('recognizes fixed error labels without retaining the device error message', function (): void {
    $summary = EnrollmentCapabilities::summary(['dataFormat' => 'jsonData'], '{"ResponseStatus":{"statusCode":4,"subStatusCode":"notSupport","errorMsg":"SECRET"}}', PushCommands::ENROLLMENT_DISCOVERY_URLS[0]);
    expect($summary)->toBe(['received' => true, 'failed' => true, 'response_error' => 'notSupport']);
});

it('does not infer supported enrollment from an XML response', function (): void {
    expect(EnrollmentCapabilities::summary(['dataFormat' => 'xmlData'], '<ResponseStatus><statusCode>1</statusCode></ResponseStatus>', PushCommands::ENROLLMENT_DISCOVERY_URLS[0]))
        ->toBe(['received' => true, 'failed' => true, 'response_error' => 'unexpected_response_format']);
});
