<?php
/*
 * This file is part of Part-DB (https://github.com/Part-DB/Part-DB-symfony).
 *
 *  Copyright (C) 2019 - 2022 Jan Böhmer (https://github.com/jbtronics)
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU Affero General Public License as published
 *  by the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU Affero General Public License for more details.
 *
 *  You should have received a copy of the GNU Affero General Public License
 *  along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

/**
 * This file is part of Part-DB (https://github.com/Part-DB/Part-DB-symfony).
 *
 * Copyright (C) 2019 - 2020 Jan Böhmer (https://github.com/jbtronics)
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published
 * by the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace App\Tests\Services\LabelSystem\BarcodeScanner;

use App\Entity\LabelSystem\LabelSupportedElement;
use App\Services\LabelSystem\BarcodeScanner\BarcodeScanHelper;
use App\Services\LabelSystem\BarcodeScanner\BarcodeScanResultInterface;
use App\Services\LabelSystem\BarcodeScanner\BarcodeSourceType;
use App\Services\LabelSystem\BarcodeScanner\EIGP114BarcodeScanResult;
use App\Services\LabelSystem\BarcodeScanner\LocalBarcodeScanResult;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class BarcodeScanHelperTest extends WebTestCase
{
    private ?BarcodeScanHelper $service = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->service = self::getContainer()->get(BarcodeScanHelper::class);
    }

    public static function dataProvider(): \Iterator
    {
        //QR URL content:
        yield [new LocalBarcodeScanResult(LabelSupportedElement::PART_LOT, 1, BarcodeSourceType::INTERNAL),
            'https://localhost:8000/scan/lot/1'];
        yield [new LocalBarcodeScanResult(LabelSupportedElement::PART, 123, BarcodeSourceType::INTERNAL),
            'https://localhost:8000/scan/part/123'];
        yield [new LocalBarcodeScanResult(LabelSupportedElement::STORELOCATION, 4, BarcodeSourceType::INTERNAL),
            'http://foo.bar/part-db/scan/location/4'];

        //Current Code39 format:
        yield [new LocalBarcodeScanResult(LabelSupportedElement::PART_LOT, 10, BarcodeSourceType::INTERNAL),
            'L0010'];
        yield [new LocalBarcodeScanResult(LabelSupportedElement::PART_LOT, 123, BarcodeSourceType::INTERNAL),
            'L0123'];
        yield [new LocalBarcodeScanResult(LabelSupportedElement::PART_LOT, 123456, BarcodeSourceType::INTERNAL),
            'L123456'];
        yield [new LocalBarcodeScanResult(LabelSupportedElement::PART, 2, BarcodeSourceType::INTERNAL),
            'P0002'];

        //Development phase Code39 barcodes:
        yield [new LocalBarcodeScanResult(LabelSupportedElement::PART_LOT, 10, BarcodeSourceType::INTERNAL),
            'L-000010'];
        yield [new LocalBarcodeScanResult(LabelSupportedElement::PART_LOT, 10, BarcodeSourceType::INTERNAL),
            'Lß000010'];
        yield [new LocalBarcodeScanResult(LabelSupportedElement::PART, 123, BarcodeSourceType::INTERNAL),
            'P-000123'];
        yield [new LocalBarcodeScanResult(LabelSupportedElement::STORELOCATION, 123, BarcodeSourceType::INTERNAL),
            'S-000123'];
        yield [new LocalBarcodeScanResult(LabelSupportedElement::PART_LOT, 12_345_678, BarcodeSourceType::INTERNAL),
            'L-12345678'];

        //Legacy storelocation format
        yield [new LocalBarcodeScanResult(LabelSupportedElement::STORELOCATION, 336, BarcodeSourceType::INTERNAL),
            '$L00336'];
        yield [new LocalBarcodeScanResult(LabelSupportedElement::STORELOCATION, 12_345_678, BarcodeSourceType::INTERNAL),
            '$L12345678'];

        //Legacy Part format
        yield [new LocalBarcodeScanResult(LabelSupportedElement::PART, 123, BarcodeSourceType::INTERNAL),
            '0000123'];
        yield [new LocalBarcodeScanResult(LabelSupportedElement::PART, 123, BarcodeSourceType::INTERNAL),
            '00001236'];
        yield [new LocalBarcodeScanResult(LabelSupportedElement::PART, 1_234_567, BarcodeSourceType::INTERNAL),
            '12345678'];

        //Test IPN barcode
        yield [new LocalBarcodeScanResult(LabelSupportedElement::PART, 2, BarcodeSourceType::IPN),
            'IPN123'];

        //Test vendor barcode
        yield [new LocalBarcodeScanResult(LabelSupportedElement::PART_LOT, 2,BarcodeSourceType::USER_DEFINED),
            'lot2_vendor_barcode'];

        $eigp114Result = new EIGP114BarcodeScanResult([
            'P' => '596-777A1-ND',
            '1P' => 'XAF4444',
            'Q' => '3',
            '10D' => '1452',
            '1T' => 'BF1103',
            '4L' => 'US',
        ]);

        yield [$eigp114Result, "[)>\x1E06\x1DP596-777A1-ND\x1D1PXAF4444\x1DQ3\x1D10D1452\x1D1TBF1103\x1D4LUS\x1E\x04"];
    }

    public static function invalidDataProvider(): \Iterator
    {
        yield ['https://localhost/part/1'];
        //Without scan
        yield ['L-'];
        //Without number
        yield ['L-123'];
        //Too short
        yield ['X-123456'];
        //Unknown prefix
        yield ['XXXWADSDF sdf'];
        //Garbage
        yield [''];
    }

    /**
     * @dataProvider dataProvider
     */
    public function testNormalizeBarcodeContent(BarcodeScanResultInterface $expected, string $input): void
    {
        $this->assertEquals($expected, $this->service->scanBarcodeContent($input));
    }

    /**
     * @dataProvider invalidDataProvider
     */
    public function testInvalidFormats(string $input): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->scanBarcodeContent($input);
    }
}
