# Missing-document investigation — October 30, 2026

## Confirmed findings

There are 13 document records across three applications. Eleven files are absent from the current `documents/` folder: six for application #4 (Burial, Approved) and five for application #5 (Medical, Pending). The two files for application #6 (OPD) still exist. The application's filename-resolution helper also fails to locate the eleven files.

Apache access logs show successful document responses on October 29, including document #9 at 22:47:16 and document #18 at 23:22:57. The first recorded 404 response in this group is October 30 at 17:14:19. The two surviving OPD files were uploaded at 17:15:30 and served successfully at 17:15:40. Times are UTC+08:00.

This establishes that the earlier uploads existed and were readable after submission. Their current absence is not merely a broken color change, a missing database row, or an initial upload that never succeeded.

## Application paths checked

- Submission saves the photos and their database records together. On failure it rolls back database inserts and removes only the newly saved files. The affected application/document tables use InnoDB. No matching submission-failure message was found in the available Apache error log.
- Document replacement removes selected database rows before committing, then removes the corresponding old files. Both affected applications still have their document records, `edit_count = 0`, and no `edited_at` value. There are no document-edit activity records. The only October 29–30 request to the edit-documents page was a GET earlier than the affected uploads, not a save.
- The other file-deletion paths affect avatars or announcement images. The backup script's cleanup targets its own temporary staging files.
- The eleven upload filenames are not tracked by Git. Upload folders have been deliberately excluded from Git since before these documents were submitted. Adding an ignore rule does **not** itself delete an existing file.

## Conclusion and limits

The database records survived while the physical upload files disappeared after successful use. The available evidence does not identify the exact deletion operation or who performed it. The normal document-edit path does not explain the remaining unchanged records. External folder cleanup, replacement of project files, or restoring a database without matching uploads could produce this state, but none is proven by these logs.

No search for backup copies or file recovery was continued after the user's instruction to investigate only. Existing records and application statuses were left intact.

## Recommended prevention

Back up the database and all uploaded-file folders together, keep a copy outside the website and on separate storage, and preserve those folders during code updates or cleanup. The corrected backup script now includes all four media folders and verifies archive contents. A backup made today preserves the current state but cannot contain the eleven files that were already missing. Identifying a future external deletion precisely would require filesystem auditing to have been enabled before it occurs.

## Completed validation

The reset-link fixes passed 48 checks using disposable applicant and admin accounts, without sending email. The backup script passed 17 checks covering all media folders, hidden protection files, unsafe destinations, missing folders and failure cleanup. A backup of the current live data was extracted and imported into a separate test database: all 24 restored table checksums and the trigger/routine/event counts matched the source. All seven archived data files passed checksum verification: the SQL dump, two surviving documents and four folder-protection files. The live database was not overwritten.
