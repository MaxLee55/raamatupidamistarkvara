-- Lisa töötajakaardile e-posti aadress palgateatise saatmiseks.
-- Käivita üks kord andmebaasis raamatupidamine.
ALTER TABLE payroll_employees
    ADD COLUMN IF NOT EXISTS email VARCHAR(254) NULL AFTER last_name;
