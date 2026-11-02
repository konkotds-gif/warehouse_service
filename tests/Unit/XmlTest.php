<?php

namespace Tests\Unit;

use App\Http\Api\Xml;
use PHPUnit\Framework\TestCase;

class XmlTest extends TestCase
{
    public function test_encode_lists_and_attributes(): void
    {
        $xml = Xml::encode(['events' => [['@attributes' => ['id' => 5, 'type' => 'contract.created'], 'contract_number' => '000123',
            'sets' => [['qr_code' => '000123', 'with_rims' => true]]]]]);
        $this->assertStringContainsString('<event id="5" type="contract.created">', $xml);
        $this->assertStringContainsString('<set>', $xml);
        $this->assertStringContainsString('<with_rims>true</with_rims>', $xml);
    }

    public function test_decode_payment_and_batch_ack(): void
    {
        $data = Xml::decode('<payment><contract>000123</contract><amount>4800.00</amount></payment>');
        $this->assertSame(['contract' => '000123', 'amount' => '4800.00'], $data);

        $ack = Xml::decode('<ack><events><event event_id="1" onec_doc_guid="x"/><event event_id="2"/></events></ack>');
        $this->assertCount(2, $ack['events']);
        $this->assertSame('1', $ack['events'][0]['event_id']);

        $single = Xml::decode('<ack><events><event event_id="7"/></events></ack>');
        $this->assertSame('7', $single['events'][0]['event_id']);
    }
}
