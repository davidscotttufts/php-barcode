# Third-party notices

php-barcode is MIT licensed (see [LICENSE](LICENSE)). It is an independent implementation of the published barcode standards. Some lookup tables were generated from the projects below, and some algorithms were written with reference to them. Each affected class names its source in its docblock.

## Zint barcode library

Used for: the PDF417/MicroPDF417 codeword tables (`src/Symbology/Pdf417/Tables.php`), the MaxiCode grid and code-set tables (`src/Symbology/MaxiCode/Tables.php`), and the Telepen table. Also a reference for the GS1 DataBar, MaxiCode, postal and linear symbologies, and for golden test vectors.

```
libzint - the open source barcode library
Copyright (C) 2008-2024 Robin Stuart <rstuart114@gmail.com>

Redistribution and use in source and binary forms, with or without
modification, are permitted provided that the following conditions
are met:

1. Redistributions of source code must retain the above copyright
   notice, this list of conditions and the following disclaimer.
2. Redistributions in binary form must reproduce the above copyright
   notice, this list of conditions and the following disclaimer in the
   documentation and/or other materials provided with the distribution.
3. Neither the name of the project nor the names of its contributors
   may be used to endorse or promote products derived from this software
   without specific prior written permission.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE
ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE
LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR
CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
POSSIBILITY OF SUCH DAMAGE.
```

## GS1 Syntax Dictionary

Used for: the Application Identifier dictionary and code lists (`src/Gs1/Resources/`), generated from <https://github.com/gs1/gs1-syntax-dictionary>.

Copyright (c) GS1 AISBL. Licensed under the Apache License, Version 2.0: <https://www.apache.org/licenses/LICENSE-2.0>.

## zxing-cpp

Reference for the Aztec high-level encoder and the PDF417 and Data Matrix encoders. Also used, as a test-time tool only, to decode generated barcodes. <https://github.com/zxing-cpp/zxing-cpp>

Licensed under the Apache License, Version 2.0: <https://www.apache.org/licenses/LICENSE-2.0>.

## Barcode Writer in Pure PostScript (BWIPP)

Reference for MaxiCode code-set selection (Bue Jensen's algorithm) and linear symbologies. <https://github.com/bwipp/postscriptbarcode>

Copyright (c) 2004-2024 Terry Burton. MIT License.

## Other references

- **Nayuki's QR Code generator library** (MIT): reference for QR Code mask evaluation and capacity tables. <https://www.nayuki.io/page/qr-code-generator-library>
- **segno** (BSD-3-Clause): used as a test-time oracle for bit-for-bit QR and Micro QR comparison. <https://github.com/heuer/segno>
- **libdmtx** (BSD-2-Clause): reference for Data Matrix placement. <https://github.com/dmtx/libdmtx>
