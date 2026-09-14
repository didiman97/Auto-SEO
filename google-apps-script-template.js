/**
 * Synka Auto SEO - Google Apps Script Two-Way Sync Engine
 * 
 * CARA MENGGUNAKAN:
 * 1. Buat Google Spreadsheet dengan susunan Header (Baris 1):
 *    Kolom A: Keyword Utama
 *    Kolom B: Kategori
 *    Kolom C: Jadwal (Contoh: 2026-09-10 09:00 atau kosongkan jika ingin langsung)
 *    Kolom D: Outline / Catatan
 *    Kolom E: Tone (Contoh: Profesional / Edukatif)
 *    Kolom F: Status (Isi dengan: Ready / Pending / Draft)
 *    Kolom G: URL Hasil Post (Akan diisi otomatis oleh WordPress)
 * 
 * 2. Masuk ke menu Extensions > Apps Script.
 * 3. Hapus kode bawaan dan tempel kode di bawah ini.
 * 4. Klik tombol "Deploy" > "New deployment" > Select type: "Web app".
 *    - Execute as: "Me"
 *    - Who has access: "Anyone"
 * 5. Salin Web App URL dan masukkan ke Pengaturan Plugin di WordPress.
 */

function doGet(e) {
  try {
    var sheet = SpreadsheetApp.getActiveSpreadsheet().getActiveSheet();
    var data = sheet.getDataRange().getValues();
    
    if (data.length < 2) {
      return ContentService.createTextOutput(JSON.stringify({ status: 'success', data: [] }))
        .setMimeType(ContentService.MimeType.JSON);
    }
    
    var headers = data[0].map(function(h) {
      return h.toString().toLowerCase().trim().replace(/[^a-z0-9_]/g, '_');
    });
    
    var rows = [];
    
    for (var i = 1; i < data.length; i++) {
      var rowData = data[i];
      var rowObj = { row_index: i + 1 };
      
      for (var j = 0; j < headers.length; j++) {
        rowObj[headers[j]] = rowData[j];
      }
      
      // Ambil kolom status & keyword
      var status = (rowObj['status'] || '').toString().toLowerCase().trim();
      var keyword = (rowObj['keyword_utama'] || rowObj['keyword'] || rowObj['topik'] || '').toString().trim();
      
      if (keyword && (status === 'ready' || status === 'pending' || status === 'siap' || status === 'queued')) {
        rows.push({
          row_index: i + 1,
          keyword: keyword,
          category: (rowObj['kategori'] || rowObj['category'] || '').toString().trim(),
          schedule: (rowObj['jadwal'] || rowObj['schedule'] || '').toString().trim(),
          outline: (rowObj['outline___catatan'] || rowObj['outline'] || rowObj['catatan'] || '').toString().trim(),
          tone: (rowObj['tone'] || rowObj['gaya_bahasa'] || '').toString().trim(),
          status: status
        });
      }
    }
    
    return ContentService.createTextOutput(JSON.stringify({ status: 'success', count: rows.length, data: rows }))
      .setMimeType(ContentService.MimeType.JSON);
      
  } catch (error) {
    return ContentService.createTextOutput(JSON.stringify({ status: 'error', message: error.toString() }))
      .setMimeType(ContentService.MimeType.JSON);
  }
}

function doPost(e) {
  try {
    var postData = JSON.parse(e.postData.contents);
    var sheet = SpreadsheetApp.getActiveSpreadsheet().getActiveSheet();
    
    var rowIndex = postData.row_index;
    var status = postData.status || 'Published';
    var postUrl = postData.post_url || '';
    
    if (!rowIndex) {
      return ContentService.createTextOutput(JSON.stringify({ status: 'error', message: 'row_index is required' }))
        .setMimeType(ContentService.MimeType.JSON);
    }
    
    // Cari kolom Status (F / Kolom 6) dan URL (G / Kolom 7)
    // Update Cell Status (Kolom F) dan URL (Kolom G)
    sheet.getRange(rowIndex, 6).setValue(status);
    if (postUrl) {
      sheet.getRange(rowIndex, 7).setValue(postUrl);
    }
    
    return ContentService.createTextOutput(JSON.stringify({ 
      status: 'success', 
      message: 'Row ' + rowIndex + ' updated successfully' 
    })).setMimeType(ContentService.MimeType.JSON);
    
  } catch (error) {
    return ContentService.createTextOutput(JSON.stringify({ status: 'error', message: error.toString() }))
      .setMimeType(ContentService.MimeType.JSON);
  }
}
