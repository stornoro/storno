/*
 * storno pkcs11 shim: wraps a vendor PKCS#11 module and fixes C_GetTokenInfo
 * returning a non-zero rv (CKR_CANCEL on Longmai mToken CryptoID) while the
 * CK_TOKEN_INFO structure is actually filled in. libp11 and pkcs11-tool treat
 * any rv != CKR_OK as "no token", so mTLS never gets past slot enumeration.
 *
 * Build:  clang -arch x86_64 -shared -o pkcs11-shim.dylib pkcs11-shim.c
 * Use:    STORNO_PKCS11_VENDOR=/opt/CryptoIDE/lib/libcryptoide_pkcs11.dylib
 */
#include <dlfcn.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

typedef unsigned long CK_RV;
typedef unsigned long CK_SLOT_ID;
typedef unsigned char CK_BYTE;
typedef CK_RV (*CK_C_GetFunctionList)(void **);
typedef CK_RV (*CK_C_GetTokenInfo)(CK_SLOT_ID, void *);

/* CK_FUNCTION_LIST: 2 bytes version (padded) then 68 function pointers (v2.40) */
#define FL_VERSION_BYTES 2
#define FL_COUNT 68
#define FL_INDEX_GETTOKENINFO 6   /* C_Initialize(0) C_Finalize(1) C_GetInfo(2) C_GetFunctionList(3) C_GetSlotList(4) C_GetSlotInfo(5) C_GetTokenInfo(6) */

static void *g_vendor = NULL;
static void **g_vendor_fl = NULL;
static void *g_shim_fl[1 + FL_COUNT];
static CK_C_GetTokenInfo g_orig_get_token_info = NULL;

#define CKR_OK 0UL
#define CKR_CANCEL 1UL
#define CKR_GENERAL_ERROR 5UL
#define CKR_TOKEN_NOT_PRESENT 0xE0UL

static CK_RV shim_GetTokenInfo(CK_SLOT_ID slot, void *info) {
    unsigned char *p = (unsigned char *)info;
    memset(p, 0, 208);
    CK_RV rv = g_orig_get_token_info(slot, info);
    if (rv == CKR_CANCEL || rv == CKR_GENERAL_ERROR) {
        /* accept when the vendor filled at least the label (first 32 bytes) */
        int filled = 0;
        for (int i = 0; i < 32; i++) if (p[i] != 0 && p[i] != ' ') { filled = 1; break; }
        if (filled) return CKR_OK;
        return CKR_TOKEN_NOT_PRESENT;
    }
    return rv;
}

static const char *vendor_path(void) {
    const char *p = getenv("STORNO_PKCS11_VENDOR");
    return (p && *p) ? p : "/opt/CryptoIDE/lib/libcryptoide_pkcs11.dylib";
}

CK_RV C_GetFunctionList(void **list) {
    if (!g_vendor) {
        g_vendor = dlopen(vendor_path(), RTLD_NOW | RTLD_LOCAL);
        if (!g_vendor) { fprintf(stderr, "pkcs11-shim: cannot load %s: %s\n", vendor_path(), dlerror()); return CKR_GENERAL_ERROR; }
        CK_C_GetFunctionList gfl = (CK_C_GetFunctionList)dlsym(g_vendor, "C_GetFunctionList");
        if (!gfl) return CKR_GENERAL_ERROR;
        CK_RV rv = gfl((void **)&g_vendor_fl);
        if (rv != CKR_OK || !g_vendor_fl) return rv ? rv : CKR_GENERAL_ERROR;
        /* copy version word + function pointers; the version occupies the first pointer slot (padded) */
        memcpy(g_shim_fl, g_vendor_fl, sizeof(g_shim_fl));
        g_orig_get_token_info = (CK_C_GetTokenInfo)g_shim_fl[1 + FL_INDEX_GETTOKENINFO];
        g_shim_fl[1 + FL_INDEX_GETTOKENINFO] = (void *)shim_GetTokenInfo;
    }
    *list = g_shim_fl;
    return CKR_OK;
}

/* Some loaders resolve C_GetTokenInfo directly by symbol (our ctypes probe does) */
CK_RV C_GetTokenInfo(CK_SLOT_ID slot, void *info) {
    if (!g_orig_get_token_info) { void *l; if (C_GetFunctionList(&l) != CKR_OK) return CKR_GENERAL_ERROR; }
    return shim_GetTokenInfo(slot, info);
}
