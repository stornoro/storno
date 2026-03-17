import com.sun.net.httpserver.HttpServer;
import com.sun.net.httpserver.HttpHandler;
import com.sun.net.httpserver.HttpExchange;

import com.helger.schematron.pure.SchematronResourcePure;
import com.helger.schematron.svrl.jaxb.SchematronOutputType;
import com.helger.schematron.svrl.jaxb.FailedAssert;
import com.helger.schematron.svrl.jaxb.SuccessfulReport;
import com.helger.schematron.svrl.jaxb.Text;

import genFactura.GenFactura;
import ro.mfinante.ValidateDetachedSignatureSanturio;

import javax.xml.transform.stream.StreamSource;
import javax.xml.validation.Schema;
import javax.xml.validation.SchemaFactory;
import javax.xml.validation.Validator;
import java.io.*;
import java.lang.reflect.Method;
import java.net.InetSocketAddress;
import java.net.URLDecoder;
import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.util.ArrayList;
import java.util.HashMap;
import java.util.List;
import java.util.Map;
import java.util.UUID;
import java.util.concurrent.Executors;
import java.util.concurrent.atomic.AtomicLong;

/**
 * Unified Java service for UBL invoice validation, PDF generation,
 * and ANAF signature verification — all on a single port.
 *
 * Eliminates JVM startup overhead for all three operations by keeping
 * libraries pre-loaded in a persistent process.
 *
 * API:
 *   POST /validate           XML body → JSON validation result
 *   POST /generate-pdf       XML body → PDF binary
 *   POST /verify-signature   JSON {xml, signature} → JSON result
 *   POST /duk/validate       XML body → JSON DUK validation result
 *   POST /duk/generate-pdf   XML body → PDF binary (DUKIntegrator)
 *   GET  /health             JSON status
 */
public class JavaServiceServer {

    private static SchematronResourcePure schematronRO;
    private static Schema xsdSchema;
    private static boolean schematronReady = false;
    private static boolean xsdReady = false;
    private static boolean pdfReady = false;
    private static boolean signatureReady = false;
    private static boolean dukReady = false;

    private static final AtomicLong validateCount = new AtomicLong(0);
    private static final AtomicLong pdfCount = new AtomicLong(0);
    private static final AtomicLong signatureCount = new AtomicLong(0);
    private static final AtomicLong dukValidateCount = new AtomicLong(0);
    private static final AtomicLong dukPdfCount = new AtomicLong(0);

    // DUKIntegrator base directory (set via system property or auto-detected)
    private static String dukDir;

    // Base directory for schema files (set via system property or CWD)
    private static String schemaDir;

    public static void main(String[] args) throws Exception {
        int port = 8082;
        if (args.length > 0) {
            port = Integer.parseInt(args[0]);
        }

        schemaDir = System.getProperty("schema.dir", System.getProperty("user.dir"));

        System.out.println("[JavaServices] Starting on port " + port + "...");
        System.out.println("[JavaServices] Schema dir: " + schemaDir);

        // ── Load Schematron ──────────────────────────────────────────
        long start = System.currentTimeMillis();
        try {
            File schFile = new File(schemaDir,
                "ro16931-ubl-1.0.9/EN16931-CIUS_RO-UBL-validation.sch");
            if (schFile.exists()) {
                schematronRO = SchematronResourcePure.fromFile(schFile);
                if (schematronRO.isValidSchematron()) {
                    schematronReady = true;
                    System.out.println("[JavaServices] Schematron loaded in " +
                        (System.currentTimeMillis() - start) + "ms");
                } else {
                    System.err.println("[JavaServices] WARNING: Invalid schematron");
                }
            } else {
                System.err.println("[JavaServices] WARNING: Schematron not found: " +
                    schFile.getAbsolutePath());
            }
        } catch (Exception e) {
            System.err.println("[JavaServices] WARNING: Schematron load failed: " +
                e.getMessage());
        }

        // ── Load XSD ─────────────────────────────────────────────────
        start = System.currentTimeMillis();
        try {
            File xsdFile = new File(schemaDir, "maindoc/UBL-Invoice-2.1.xsd");
            if (xsdFile.exists()) {
                SchemaFactory factory = SchemaFactory.newInstance(
                    "http://www.w3.org/2001/XMLSchema");
                xsdSchema = factory.newSchema(xsdFile);
                xsdReady = true;
                System.out.println("[JavaServices] XSD loaded in " +
                    (System.currentTimeMillis() - start) + "ms");
            } else {
                System.err.println("[JavaServices] WARNING: XSD not found: " +
                    xsdFile.getAbsolutePath());
            }
        } catch (Exception e) {
            System.err.println("[JavaServices] WARNING: XSD load failed: " +
                e.getMessage());
        }

        // ── Warm up GenFactura ───────────────────────────────────────
        start = System.currentTimeMillis();
        try {
            new GenFactura();
            pdfReady = true;
            System.out.println("[JavaServices] GenFactura loaded in " +
                (System.currentTimeMillis() - start) + "ms");
        } catch (Exception e) {
            System.err.println("[JavaServices] WARNING: GenFactura load failed: " +
                e.getMessage());
        }

        // ── Warm up Signature Verifier ───────────────────────────────
        start = System.currentTimeMillis();
        try {
            Class.forName("ro.mfinante.ValidateDetachedSignatureSanturio");
            signatureReady = true;
            System.out.println("[JavaServices] Signature verifier loaded in " +
                (System.currentTimeMillis() - start) + "ms");
        } catch (Exception e) {
            System.err.println("[JavaServices] WARNING: Signature verifier load failed: " +
                e.getMessage());
        }

        // ── Warm up DUKIntegrator ───────────────────────────────────
        start = System.currentTimeMillis();
        dukDir = System.getProperty("duk.dir", "");
        if (dukDir.isEmpty()) {
            // Auto-detect: look for DUKIntegrator.jar in tools/duk-integrator/
            String baseDir = System.getProperty("user.dir");
            File candidate = new File(baseDir, "tools/duk-integrator/DUKIntegrator.jar");
            if (candidate.exists()) {
                dukDir = candidate.getParent();
            }
        }
        try {
            if (!dukDir.isEmpty() && new File(dukDir, "DUKIntegrator.jar").exists()) {
                // Verify we can load the Integrator class (package: general)
                Class.forName("general.Integrator");
                dukReady = true;
                System.out.println("[JavaServices] DUKIntegrator loaded in " +
                    (System.currentTimeMillis() - start) + "ms (dir: " + dukDir + ")");
            } else {
                System.err.println("[JavaServices] WARNING: DUKIntegrator not found" +
                    (dukDir.isEmpty() ? "" : " in " + dukDir));
            }
        } catch (Exception e) {
            System.err.println("[JavaServices] WARNING: DUKIntegrator load failed: " +
                e.getMessage());
        }

        // ── Start HTTP server ────────────────────────────────────────
        int threads = Math.max(4, Runtime.getRuntime().availableProcessors());
        HttpServer server = HttpServer.create(new InetSocketAddress("127.0.0.1", port), 0);
        server.createContext("/validate", new ValidateHandler());
        server.createContext("/generate-pdf", new PdfHandler());
        server.createContext("/verify-signature", new SignatureHandler());
        server.createContext("/duk/validate", new DukValidateHandler());
        server.createContext("/duk/generate-pdf", new DukPdfHandler());
        server.createContext("/health", new HealthHandler());
        server.setExecutor(Executors.newFixedThreadPool(threads));
        server.start();

        System.out.println("[JavaServices] Ready — http://127.0.0.1:" + port);
        System.out.println("[JavaServices]   /validate          " +
            (schematronReady ? "OK" : "UNAVAILABLE"));
        System.out.println("[JavaServices]   /generate-pdf      " +
            (pdfReady ? "OK" : "UNAVAILABLE"));
        System.out.println("[JavaServices]   /verify-signature  " +
            (signatureReady ? "OK" : "UNAVAILABLE"));
        System.out.println("[JavaServices]   /duk/validate      " +
            (dukReady ? "OK" : "UNAVAILABLE"));
        System.out.println("[JavaServices]   /duk/generate-pdf  " +
            (dukReady ? "OK" : "UNAVAILABLE"));
        System.out.println("[JavaServices]   Thread pool: " + threads);
    }

    // ═════════════════════════════════════════════════════════════════
    // Health
    // ═════════════════════════════════════════════════════════════════

    static class HealthHandler implements HttpHandler {
        @Override
        public void handle(HttpExchange ex) throws IOException {
            String json = "{\"status\":\"ok\"" +
                ",\"schematron\":" + schematronReady +
                ",\"xsd\":" + xsdReady +
                ",\"pdf\":" + pdfReady +
                ",\"signature\":" + signatureReady +
                ",\"duk\":" + dukReady +
                ",\"stats\":{" +
                    "\"validations\":" + validateCount.get() +
                    ",\"pdfs\":" + pdfCount.get() +
                    ",\"signatures\":" + signatureCount.get() +
                    ",\"dukValidations\":" + dukValidateCount.get() +
                    ",\"dukPdfs\":" + dukPdfCount.get() +
                "}}";
            sendJson(ex, 200, json);
        }
    }

    // ═════════════════════════════════════════════════════════════════
    // POST /validate — Schematron + XSD validation
    // ═════════════════════════════════════════════════════════════════

    static class ValidateHandler implements HttpHandler {
        @Override
        public void handle(HttpExchange ex) throws IOException {
            if (!"POST".equalsIgnoreCase(ex.getRequestMethod())) {
                sendJson(ex, 405, "{\"error\":\"Method not allowed\"}");
                return;
            }

            String xml = readBody(ex);
            if (xml.isEmpty()) {
                sendJson(ex, 400, "{\"error\":\"Empty body\"}");
                return;
            }

            // Reject if validation services not ready
            if (!xsdReady && !schematronReady) {
                sendJson(ex, 503,
                    "{\"error\":\"Validation services unavailable (XSD and Schematron not loaded)\"}");
                return;
            }

            validateCount.incrementAndGet();
            long start = System.currentTimeMillis();
            List<ErrorEntry> errors = new ArrayList<>();

            // XSD validation
            if (xsdReady) {
                try {
                    Validator v = xsdSchema.newValidator();
                    v.validate(new StreamSource(new StringReader(xml)));
                } catch (org.xml.sax.SAXException e) {
                    errors.add(new ErrorEntry(e.getMessage(), "xsd", null, null));
                } catch (Exception e) {
                    errors.add(new ErrorEntry(
                        "XSD error: " + e.getMessage(), "xsd", null, null));
                }
            }

            // Schematron validation (skip if XSD failed)
            if (errors.isEmpty() && schematronReady) {
                try {
                    SchematronOutputType result =
                        schematronRO.applySchematronValidationToSVRL(
                            new StreamSource(new StringReader(xml)));
                    if (result != null) {
                        for (Object item :
                                result.getActivePatternAndFiredRuleAndFailedAssert()) {
                            if (item instanceof FailedAssert fa) {
                                errors.add(new ErrorEntry(
                                    extractText(fa.getDiagnosticReferenceOrPropertyReferenceOrText()),
                                    "schematron", fa.getId(), fa.getLocation()));
                            } else if (item instanceof SuccessfulReport sr) {
                                errors.add(new ErrorEntry(
                                    extractText(sr.getDiagnosticReferenceOrPropertyReferenceOrText()),
                                    "schematron", sr.getId(), sr.getLocation()));
                            }
                        }
                    }
                } catch (Exception e) {
                    errors.add(new ErrorEntry(
                        "Schematron error: " + e.getMessage(),
                        "schematron", null, null));
                }
            }

            long elapsed = System.currentTimeMillis() - start;
            StringBuilder json = new StringBuilder();
            json.append("{\"valid\":").append(errors.isEmpty());
            json.append(",\"schematronAvailable\":").append(schematronReady);
            json.append(",\"xsdAvailable\":").append(xsdReady);
            json.append(",\"elapsed_ms\":").append(elapsed);
            json.append(",\"errors\":[");
            for (int i = 0; i < errors.size(); i++) {
                if (i > 0) json.append(",");
                json.append(errors.get(i).toJson());
            }
            json.append("]}");
            sendJson(ex, 200, json.toString());
        }

        private String extractText(List<?> items) {
            for (Object item : items) {
                if (item instanceof Text text) {
                    for (Object content : text.getContent()) {
                        if (content instanceof String s) {
                            return s.replaceAll("[\\t\\r\\n\"]", "").trim();
                        }
                    }
                }
            }
            return null;
        }
    }

    // ═════════════════════════════════════════════════════════════════
    // POST /generate-pdf — PDF generation
    // ═════════════════════════════════════════════════════════════════

    static class PdfHandler implements HttpHandler {
        @Override
        public void handle(HttpExchange ex) throws IOException {
            if (!"POST".equalsIgnoreCase(ex.getRequestMethod())) {
                sendJson(ex, 405, "{\"error\":\"Method not allowed\"}");
                return;
            }

            if (!pdfReady) {
                sendJson(ex, 503,
                    "{\"error\":\"PDF generation unavailable (GenFactura not loaded)\"}");
                return;
            }

            byte[] xmlBytes;
            try (InputStream is = ex.getRequestBody()) {
                xmlBytes = is.readAllBytes();
            }
            if (xmlBytes.length == 0) {
                sendJson(ex, 400, "{\"error\":\"Empty body\"}");
                return;
            }

            long reqId = pdfCount.incrementAndGet();
            String id = UUID.randomUUID().toString();
            File tmpXml = new File(System.getProperty("java.io.tmpdir"),
                "pdfgen_" + id + ".xml");
            File tmpPdf = new File(System.getProperty("java.io.tmpdir"),
                "pdfgen_" + id + ".pdf");
            File tmpAtas = new File(System.getProperty("java.io.tmpdir"),
                "pdfgen_" + id + "_ATAS");

            try {
                Files.write(tmpXml.toPath(), xmlBytes);
                long start = System.currentTimeMillis();

                GenFactura gen = new GenFactura();
                String type = gen.identificaDeclaratie(tmpXml.getAbsolutePath());
                String error = gen.getError();

                if (error != null && !error.isEmpty()) {
                    sendJson(ex, 422,
                        "{\"error\":" + escapeJson("Document type error: " + error) + "}");
                    return;
                }
                if (type == null || type.isEmpty()) {
                    sendJson(ex, 422, "{\"error\":\"Unknown document type\"}");
                    return;
                }

                String pdfPath = gen.generarePDF(tmpXml.getAbsolutePath(), type);
                String genError = gen.getError();
                if (genError != null && !genError.isEmpty()) {
                    sendJson(ex, 500,
                        "{\"error\":" + escapeJson("PDF error: " + genError) + "}");
                    return;
                }

                File pdfFile = (pdfPath != null && !pdfPath.isEmpty())
                    ? new File(pdfPath) : tmpPdf;
                if (!pdfFile.exists()) {
                    sendJson(ex, 500, "{\"error\":\"PDF not created\"}");
                    return;
                }

                long elapsed = System.currentTimeMillis() - start;
                System.out.println("[JavaServices] PDF #" + reqId + " " +
                    elapsed + "ms (" + type + ", " + pdfFile.length() + "b)");

                byte[] pdfBytes = Files.readAllBytes(pdfFile.toPath());
                ex.getResponseHeaders().set("Content-Type", "application/pdf");
                ex.getResponseHeaders().set("X-Generation-Time-Ms",
                    String.valueOf(elapsed));
                ex.sendResponseHeaders(200, pdfBytes.length);
                try (OutputStream os = ex.getResponseBody()) {
                    os.write(pdfBytes);
                }

                if (!pdfFile.equals(tmpPdf)) pdfFile.delete();

            } catch (Exception e) {
                System.err.println("[JavaServices] PDF #" + reqId +
                    " error: " + e.getMessage());
                sendJson(ex, 500,
                    "{\"error\":" + escapeJson("PDF failed: " + e.getMessage()) + "}");
            } finally {
                tmpXml.delete();
                tmpPdf.delete();
                tmpAtas.delete();
            }
        }
    }

    // ═════════════════════════════════════════════════════════════════
    // POST /verify-signature — ANAF signature verification
    // ═════════════════════════════════════════════════════════════════

    static class SignatureHandler implements HttpHandler {
        @Override
        public void handle(HttpExchange ex) throws IOException {
            if (!"POST".equalsIgnoreCase(ex.getRequestMethod())) {
                sendJson(ex, 405, "{\"error\":\"Method not allowed\"}");
                return;
            }

            if (!signatureReady) {
                sendJson(ex, 503,
                    "{\"error\":\"Signature verification unavailable (verifier not loaded)\"}");
                return;
            }

            String body = readBody(ex);
            if (body.isEmpty()) {
                sendJson(ex, 400, "{\"error\":\"Empty body\"}");
                return;
            }

            String xmlContent = extractJsonField(body, "xml");
            String sigContent = extractJsonField(body, "signature");
            if (xmlContent == null || sigContent == null) {
                sendJson(ex, 400,
                    "{\"error\":\"Body must contain 'xml' and 'signature' fields\"}");
                return;
            }

            long reqId = signatureCount.incrementAndGet();
            String id = UUID.randomUUID().toString();
            File tmpXml = new File(System.getProperty("java.io.tmpdir"),
                "sigverif_" + id + ".xml");
            File tmpSig = new File(System.getProperty("java.io.tmpdir"),
                "sigverif_" + id + "_sig.xml");

            try {
                Files.writeString(tmpXml.toPath(), xmlContent);
                Files.writeString(tmpSig.toPath(), sigContent);

                long start = System.currentTimeMillis();
                String result = ValidateDetachedSignatureSanturio.verify(
                    tmpXml.getAbsolutePath(), tmpSig.getAbsolutePath());
                long elapsed = System.currentTimeMillis() - start;

                boolean valid = result != null
                    && result.contains("validate cu succes")
                    && !result.contains("Nu au putut fi validate");

                String message = result != null ? result : "No result";

                System.out.println("[JavaServices] Sig #" + reqId + " " +
                    elapsed + "ms — " + (valid ? "VALID" : "INVALID"));

                sendJson(ex, 200,
                    "{\"valid\":" + valid +
                    ",\"message\":" + escapeJson(message) +
                    ",\"elapsed_ms\":" + elapsed + "}");

            } catch (Exception e) {
                System.err.println("[JavaServices] Sig #" + reqId +
                    " error: " + e.getMessage());
                sendJson(ex, 200,
                    "{\"valid\":false,\"message\":" +
                    escapeJson("Error: " + e.getMessage()) + "}");
            } finally {
                tmpXml.delete();
                tmpSig.delete();
            }
        }
    }

    // ═════════════════════════════════════════════════════════════════
    // POST /duk/validate — DUKIntegrator validation
    // ═════════════════════════════════════════════════════════════════

    static class DukValidateHandler implements HttpHandler {
        @Override
        public void handle(HttpExchange ex) throws IOException {
            if (!"POST".equalsIgnoreCase(ex.getRequestMethod())) {
                sendJson(ex, 405, "{\"error\":\"Method not allowed\"}");
                return;
            }

            if (!dukReady) {
                sendJson(ex, 503,
                    "{\"error\":\"DUK validation unavailable (DUKIntegrator not loaded)\"}");
                return;
            }

            byte[] xmlBytes;
            try (InputStream is = ex.getRequestBody()) {
                xmlBytes = is.readAllBytes();
            }
            if (xmlBytes.length == 0) {
                sendJson(ex, 400, "{\"error\":\"Empty body\"}");
                return;
            }

            // Extract ?type=D394 from query string
            String type = parseQueryParam(ex.getRequestURI().getRawQuery(), "type");
            if (type == null || type.isEmpty()) {
                sendJson(ex, 400, "{\"error\":\"Missing query parameter: type (e.g. ?type=D394)\"}");
                return;
            }

            long reqId = dukValidateCount.incrementAndGet();
            String id = UUID.randomUUID().toString();
            File tmpXml = new File(System.getProperty("java.io.tmpdir"),
                "dukval_" + id + ".xml");
            // DUKIntegrator writes errors to <filename>.err.txt
            File tmpErr = new File(System.getProperty("java.io.tmpdir"),
                "dukval_" + id + ".xml.err.txt");

            try {
                Files.write(tmpXml.toPath(), xmlBytes);
                long start = System.currentTimeMillis();

                // Use general.Integrator API (from DUKIntegrator.jar)
                // parseDocument(xmlPath, type) returns int: 0=success, >0=errors
                Class<?> intClass = Class.forName("general.Integrator");
                Object integrator = intClass.getDeclaredConstructor().newInstance();

                // Set declaration type
                Method setType = intClass.getMethod("setDeclType", String.class);
                setType.invoke(integrator, type);

                // Set config path to the DUK directory
                Method setConfig = intClass.getMethod("setConfigPath", String.class);
                setConfig.invoke(integrator, dukDir + "/");

                Method parseMethod = intClass.getMethod("parseDocument", String.class, String.class);
                int result = (Integer) parseMethod.invoke(integrator, tmpXml.getAbsolutePath(), type);
                boolean valid = (result == 0);

                long elapsed = System.currentTimeMillis() - start;

                // Read errors from the integrator's error file
                List<String> errors = new ArrayList<>();
                List<String> warnings = new ArrayList<>();

                // Try integrator's own error file accessor
                Method getErrFile = intClass.getMethod("getFisierEroriParsare");
                String errFilePath = (String) getErrFile.invoke(integrator);
                File errFile = (errFilePath != null && !errFilePath.isEmpty())
                    ? new File(errFilePath) : tmpErr;

                if (errFile.exists()) {
                    String errContent = Files.readString(errFile.toPath(), StandardCharsets.UTF_8);
                    for (String line : errContent.split("\\r?\\n")) {
                        line = line.trim();
                        if (line.isEmpty()) continue;
                        if (line.startsWith("WARNING:") || line.startsWith("Avertisment:")) {
                            warnings.add(line);
                        } else {
                            errors.add(line);
                        }
                    }
                    errFile.delete();
                }

                // Also check the log errors file
                Method getLogFile = intClass.getMethod("getFisierLogErori");
                String logFilePath = (String) getLogFile.invoke(integrator);
                if (logFilePath != null && !logFilePath.isEmpty()) {
                    File logFile = new File(logFilePath);
                    if (logFile.exists()) {
                        String logContent = Files.readString(logFile.toPath(), StandardCharsets.UTF_8);
                        for (String line : logContent.split("\\r?\\n")) {
                            line = line.trim();
                            if (line.isEmpty()) continue;
                            if (!errors.contains(line) && !warnings.contains(line)) {
                                errors.add(line);
                            }
                        }
                        logFile.delete();
                    }
                }

                // If DUK says invalid but no errors captured, add a generic one
                if (!valid && errors.isEmpty()) {
                    errors.add("DUK validation failed for type " + type + " (code: " + result + ")");
                }

                System.out.println("[JavaServices] DUK validate #" + reqId + " " +
                    elapsed + "ms — " + type + " " + (valid ? "VALID" : "INVALID") +
                    " (" + errors.size() + " errors, " + warnings.size() + " warnings)");

                StringBuilder json = new StringBuilder();
                json.append("{\"valid\":").append(valid && errors.isEmpty());
                json.append(",\"elapsed_ms\":").append(elapsed);
                json.append(",\"errors\":[");
                for (int i = 0; i < errors.size(); i++) {
                    if (i > 0) json.append(",");
                    json.append(escapeJson(errors.get(i)));
                }
                json.append("],\"warnings\":[");
                for (int i = 0; i < warnings.size(); i++) {
                    if (i > 0) json.append(",");
                    json.append(escapeJson(warnings.get(i)));
                }
                json.append("]}");
                sendJson(ex, 200, json.toString());

            } catch (Exception e) {
                System.err.println("[JavaServices] DUK validate #" + reqId +
                    " error: " + e.getMessage());
                sendJson(ex, 500,
                    "{\"error\":" + escapeJson("DUK validation failed: " + e.getMessage()) + "}");
            } finally {
                tmpXml.delete();
                tmpErr.delete();
            }
        }
    }

    // ═════════════════════════════════════════════════════════════════
    // POST /duk/generate-pdf — DUKIntegrator PDF generation
    // ═════════════════════════════════════════════════════════════════

    static class DukPdfHandler implements HttpHandler {
        @Override
        public void handle(HttpExchange ex) throws IOException {
            if (!"POST".equalsIgnoreCase(ex.getRequestMethod())) {
                sendJson(ex, 405, "{\"error\":\"Method not allowed\"}");
                return;
            }

            if (!dukReady) {
                sendJson(ex, 503,
                    "{\"error\":\"DUK PDF generation unavailable (DUKIntegrator not loaded)\"}");
                return;
            }

            byte[] xmlBytes;
            try (InputStream is = ex.getRequestBody()) {
                xmlBytes = is.readAllBytes();
            }
            if (xmlBytes.length == 0) {
                sendJson(ex, 400, "{\"error\":\"Empty body\"}");
                return;
            }

            String type = parseQueryParam(ex.getRequestURI().getRawQuery(), "type");
            if (type == null || type.isEmpty()) {
                sendJson(ex, 400, "{\"error\":\"Missing query parameter: type (e.g. ?type=D394)\"}");
                return;
            }

            long reqId = dukPdfCount.incrementAndGet();
            String id = UUID.randomUUID().toString();
            File tmpXml = new File(System.getProperty("java.io.tmpdir"),
                "dukpdf_" + id + ".xml");
            File tmpPdf = new File(System.getProperty("java.io.tmpdir"),
                "dukpdf_" + id + ".pdf");
            File tmpErr = new File(System.getProperty("java.io.tmpdir"),
                "dukpdf_" + id + ".xml.err.txt");

            try {
                Files.write(tmpXml.toPath(), xmlBytes);
                long start = System.currentTimeMillis();

                // Use general.Integrator API for PDF generation
                // pdfCreation(xmlPath, type, outputDir, null) returns int: 0=success
                Class<?> intClass = Class.forName("general.Integrator");
                Object integrator = intClass.getDeclaredConstructor().newInstance();

                Method setType = intClass.getMethod("setDeclType", String.class);
                setType.invoke(integrator, type);

                Method setConfig = intClass.getMethod("setConfigPath", String.class);
                setConfig.invoke(integrator, dukDir + "/");

                // Don't sign the PDF (we sign separately with USB cert)
                Method noSign = intClass.getMethod("setNoCertificate");
                noSign.invoke(integrator);

                String outputDir = System.getProperty("java.io.tmpdir");
                Method pdfMethod = intClass.getMethod("pdfCreation",
                    String.class, String.class, String.class, String.class);
                int pdfResult = (Integer) pdfMethod.invoke(integrator,
                    tmpXml.getAbsolutePath(), type, outputDir, null);

                // Get the generated PDF path from the integrator
                Method getPdfFile = intClass.getMethod("getFisierPdf");
                String pdfPath = (String) getPdfFile.invoke(integrator);

                // Check for errors
                if (tmpErr.exists()) {
                    String errContent = Files.readString(tmpErr.toPath(), StandardCharsets.UTF_8).trim();
                    if (!errContent.isEmpty()) {
                        // Only fail if the error file has actual errors (not warnings)
                        boolean hasErrors = false;
                        for (String line : errContent.split("\\r?\\n")) {
                            if (!line.trim().startsWith("WARNING:") && !line.trim().startsWith("Avertisment:") && !line.trim().isEmpty()) {
                                hasErrors = true;
                                break;
                            }
                        }
                        if (hasErrors) {
                            sendJson(ex, 422,
                                "{\"error\":" + escapeJson("DUK PDF validation errors: " + errContent) + "}");
                            return;
                        }
                    }
                }

                File pdfFile = (pdfPath != null && !pdfPath.isEmpty())
                    ? new File(pdfPath) : tmpPdf;

                if (!pdfFile.exists()) {
                    // DUK sometimes puts the PDF next to the XML with .pdf extension
                    File altPdf = new File(tmpXml.getAbsolutePath().replaceAll("\\.xml$", ".pdf"));
                    if (altPdf.exists()) {
                        pdfFile = altPdf;
                    } else {
                        sendJson(ex, 500, "{\"error\":\"DUK PDF not created\"}");
                        return;
                    }
                }

                long elapsed = System.currentTimeMillis() - start;
                System.out.println("[JavaServices] DUK PDF #" + reqId + " " +
                    elapsed + "ms (" + type + ", " + pdfFile.length() + "b)");

                byte[] pdfBytes = Files.readAllBytes(pdfFile.toPath());
                ex.getResponseHeaders().set("Content-Type", "application/pdf");
                ex.getResponseHeaders().set("X-Generation-Time-Ms",
                    String.valueOf(elapsed));
                ex.sendResponseHeaders(200, pdfBytes.length);
                try (OutputStream os = ex.getResponseBody()) {
                    os.write(pdfBytes);
                }

                if (!pdfFile.equals(tmpPdf)) pdfFile.delete();

            } catch (Exception e) {
                System.err.println("[JavaServices] DUK PDF #" + reqId +
                    " error: " + e.getMessage());
                sendJson(ex, 500,
                    "{\"error\":" + escapeJson("DUK PDF failed: " + e.getMessage()) + "}");
            } finally {
                tmpXml.delete();
                tmpPdf.delete();
                tmpErr.delete();
            }
        }
    }

    // ═════════════════════════════════════════════════════════════════
    // Helpers
    // ═════════════════════════════════════════════════════════════════

    record ErrorEntry(String message, String source, String ruleId, String location) {
        String toJson() {
            StringBuilder sb = new StringBuilder("{");
            sb.append("\"message\":").append(escapeJson(message));
            sb.append(",\"source\":").append(escapeJson(source));
            if (ruleId != null) sb.append(",\"ruleId\":").append(escapeJson(ruleId));
            if (location != null) sb.append(",\"location\":").append(escapeJson(location));
            sb.append("}");
            return sb.toString();
        }
    }

    static String readBody(HttpExchange ex) throws IOException {
        try (InputStream is = ex.getRequestBody()) {
            return new String(is.readAllBytes(), StandardCharsets.UTF_8);
        }
    }

    static String extractJsonField(String json, String field) {
        String key = "\"" + field + "\"";
        int keyIdx = json.indexOf(key);
        if (keyIdx < 0) return null;
        int colonIdx = json.indexOf(':', keyIdx + key.length());
        if (colonIdx < 0) return null;
        int startQuote = json.indexOf('"', colonIdx + 1);
        if (startQuote < 0) return null;
        StringBuilder sb = new StringBuilder();
        for (int i = startQuote + 1; i < json.length(); i++) {
            char c = json.charAt(i);
            if (c == '\\' && i + 1 < json.length()) {
                char next = json.charAt(i + 1);
                switch (next) {
                    case '"': sb.append('"'); i++; break;
                    case '\\': sb.append('\\'); i++; break;
                    case '/': sb.append('/'); i++; break;
                    case 'n': sb.append('\n'); i++; break;
                    case 'r': sb.append('\r'); i++; break;
                    case 't': sb.append('\t'); i++; break;
                    case 'u':
                        if (i + 5 < json.length()) {
                            String hex = json.substring(i + 2, i + 6);
                            sb.append((char) Integer.parseInt(hex, 16));
                            i += 5;
                        } else {
                            sb.append(c);
                        }
                        break;
                    default: sb.append(c); break;
                }
            } else if (c == '"') {
                return sb.toString();
            } else {
                sb.append(c);
            }
        }
        return null;
    }

    static String escapeJson(String s) {
        if (s == null) return "null";
        return "\"" + s.replace("\\", "\\\\")
                        .replace("\"", "\\\"")
                        .replace("\n", "\\n")
                        .replace("\r", "\\r")
                        .replace("\t", "\\t") + "\"";
    }

    static String parseQueryParam(String query, String param) {
        if (query == null || query.isEmpty()) return null;
        for (String pair : query.split("&")) {
            String[] kv = pair.split("=", 2);
            if (kv.length == 2 && kv[0].equals(param)) {
                try {
                    return URLDecoder.decode(kv[1], StandardCharsets.UTF_8);
                } catch (Exception e) {
                    return kv[1];
                }
            }
        }
        return null;
    }

    static void sendJson(HttpExchange ex, int code, String body) throws IOException {
        byte[] bytes = body.getBytes(StandardCharsets.UTF_8);
        ex.getResponseHeaders().set("Content-Type", "application/json");
        ex.sendResponseHeaders(code, bytes.length);
        try (OutputStream os = ex.getResponseBody()) {
            os.write(bytes);
        }
    }
}
