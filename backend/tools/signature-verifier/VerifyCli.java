import ro.mfinante.ValidateDetachedSignatureSanturio;

public class VerifyCli {
    public static void main(String[] args) {
        if (args.length < 2) {
            System.err.println("Usage: VerifyCli <invoice.xml> <signature.xml>");
            System.exit(1);
        }

        String xmlPath = args[0];
        String signaturePath = args[1];

        try {
            String result = ValidateDetachedSignatureSanturio.verify(xmlPath, signaturePath);

            if (result != null
                && result.contains("validate cu succes")
                && !result.contains("Nu au putut fi validate")) {
                System.out.println("VALID");
            } else {
                System.out.println("INVALID");
            }
            System.out.println(result != null ? result : "No result returned");
        } catch (Exception e) {
            System.out.println("INVALID");
            System.out.println("Error: " + e.getMessage());
            System.exit(2);
        }
    }
}
