import genFactura.GenFactura;

/**
 * Headless CLI wrapper for GenFactura PDF generation.
 * Usage: java -cp ... PdfCli <input.xml>
 * Outputs the generated PDF path to stdout on success.
 * Prints error to stderr and exits with code 1 on failure.
 */
public class PdfCli {
    public static void main(String[] args) {
        if (args.length < 1) {
            System.err.println("Usage: PdfCli <input.xml>");
            System.exit(1);
        }

        String xmlPath = args[0];
        GenFactura gen = new GenFactura();

        // Step 1: Detect document type — returns Romanian name ("Factura" / "Nota de Creditare")
        // which is also what generarePDF expects as its type parameter
        String type = gen.identificaDeclaratie(xmlPath);
        String error = gen.getError();

        if (error != null && !error.isEmpty()) {
            System.err.println("Failed to identify document type: " + error);
            System.exit(1);
        }
        if (type == null || type.isEmpty()) {
            System.err.println("Failed to identify document type: unknown document root element");
            System.exit(1);
        }

        // Step 2: Generate PDF
        try {
            String pdfPath = gen.generarePDF(xmlPath, type);
            String genError = gen.getError();

            if (genError != null && !genError.isEmpty()) {
                System.err.println("PDF generation error: " + genError);
                System.exit(1);
            }

            if (pdfPath == null || pdfPath.isEmpty()) {
                // Derive the expected PDF path from XML path
                int dotIdx = xmlPath.lastIndexOf('.');
                pdfPath = (dotIdx > 0 ? xmlPath.substring(0, dotIdx) : xmlPath) + ".pdf";
            }

            java.io.File pdf = new java.io.File(pdfPath);
            if (!pdf.exists()) {
                System.err.println("PDF file was not created: " + pdfPath);
                System.exit(1);
            }

            System.out.println(pdfPath);
        } catch (Exception e) {
            System.err.println("PDF generation failed: " + e.getMessage());
            System.exit(1);
        }
    }
}
