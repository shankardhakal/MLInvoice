<?php
/*******************************************************************************
 MLInvoice: web-based invoicing application.
 Copyright (C) 2010-2017 Ere Maijala

 This program is free software. See attached LICENSE.

 *******************************************************************************/

/*******************************************************************************
 MLInvoice: web-pohjainen laskutusohjelma.
 Copyright (C) 2010-2017 Ere Maijala

 Tämä ohjelma on vapaa. Lue oheinen LICENSE.

 *******************************************************************************/
require_once 'invoice_printer_xslt.php';
require_once 'htmlfuncs.php';
require_once 'miscfuncs.php';

class InvoicePrinterFinvoiceSOAP extends InvoicePrinterXSLT
{
    public function printInvoice()
    {
        // First create the actual Finvoice
        $this->xsltParams['printTransmissionDetails'] = true;
        parent::transform('create_finvoice.xsl', 'Finvoice.xsd');
        $finvoice = $this->xml;

        // Create the SOAP envelope
        parent::transform('create_finvoice_soap_envelope.xsl');

        header('Content-Type: text/xml; charset=ISO-8859-15');
        $filename = $this->getPrintoutFileName();
        if ($this->printStyle) {
            header("Content-Disposition: inline; filename=$filename");
        } else {
            header("Content-Disposition: attachment; filename=$filename");
        }
        echo $this->xml . "\n$finvoice";
    }
}
